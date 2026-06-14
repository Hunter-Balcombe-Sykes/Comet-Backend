
<!-- ═══ LENS: lifecycle-correctness | CHUNK: account-site ═══ -->

- [ ] **#LIFE-1** · P1 — ExportUserDataJob dispatched inside a DB transaction without `afterCommit`, causing silent export loss
    - **Where:** app/Services/User/DataExport/DataExportService.php:69 (dispatch call); app/Jobs/Gdpr/ExportUserDataJob.php:26-28 (constructor missing `$this->afterCommit = true`)
    - **Affects:** Users requesting GDPR data exports — the export job can execute before the transaction commits, find no audit row, and silently exit. The export is permanently lost with no retry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->afterCommit = true;` to `ExportUserDataJob`'s constructor.
        - Optionally throw an exception in `handle()` when the audit row is null (instead of `return`) so the job retries rather than silently vanishing.
    - **Technical:** `DataExportService::dispatch()` wraps the audit-row insert + job dispatch in `DB::connection('pgsql')->transaction()`. Without `afterCommit`, Laravel releases the job onto the Redis queue before the transaction commits. A fast worker picks it up, `DataExportAudit::find($this->auditId)` returns null, the job logs a warning and returns — no retry, no exception, no export. The equivalent `SendAccountDeletionRequestMailJob` has `$this->afterCommit = true` in its constructor; `ExportUserDataJob` is missing it.
    - **Plain English:** Think of submitting a data-download request at a government office. The clerk fills out a form (audit row) and puts it in a tray (Redis queue). If the processing team grabs the form before the clerk finishes signing it, they see a blank form and toss it in the bin. The request is lost forever, and the citizen never gets their download link.
    - **Evidence:**
        ```php
        // DataExportService.php: dispatch() — job queued inside transaction
        return DB::connection('pgsql')->transaction(function () use (...) {
            // ...
            $audit = DataExportAudit::create([...]);
            ExportUserDataJob::dispatch($audit->id);  // no afterCommit
            return $audit;
        });
        ```
        ```php
        // ExportUserDataJob.php: constructor — afterCommit missing
        public function __construct(public string $auditId)
        {
            $this->onQueue(config('partna.gdpr.queue'));
            // $this->afterCommit = true;  // ← MISSING
        }
        ```
        ```php
        // ExportUserDataJob.php: handle() — silently returns on null audit
        $audit = DataExportAudit::find($this->auditId);
        if (! $audit) {
            Log::warning('ExportUserDataJob: audit row not found', ['audit_id' => $this->auditId]);
            return;  // export lost, no retry
        }
        ```
    - `[DRAFT, confidence: 0.92]`

- [ ] **#LIFE-2** · P1 — UpdateSiteAction reads subdomain cooldown clock outside the DB transaction, enabling cooldown bypass via concurrent requests
    - **Where:** app/Services/Site/UpdateSiteAction.php (cooldown check at `$site->subdomain_changed_at` before `DB::transaction()`)
    - **Affects:** Any user who submits two concurrent rename requests within the cooldown window — both can succeed, bypassing the 30-day rename limit.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move the cooldown read into the transaction, acquiring a row lock on the site row with `lockForUpdate()` before reading `subdomain_changed_at`.
        - Alternatively, use a DB-level atomic conditional: `UPDATE ... WHERE subdomain_changed_at < now() - interval '30 days'` and check `rowCount()`.
    - **Technical:** The `UpdateSiteAction::execute()` method reads `$site->subdomain_changed_at` BEFORE entering the `DB::transaction()` closure. Two concurrent requests (A and B) both read the same old timestamp, both pass the cooldown check, then both enter their respective transactions. A commits first, updating `subdomain_changed_at` to `now()`. B then commits, updating it again — effectively allowing two renames within the cooldown window. This is a classic TOCTOU (time-of-check-time-of-use) race. The `lockForUpdate` pattern used elsewhere in `ReclaimHandleAction` for alias rows is the correct fix — extend it to the site row for cooldown enforcement.
    - **Plain English:** A building has a rule: you can only change your room number once every 30 days. The receptionist checks your file at the front desk, then walks to the back office to make the change. If two receptionists both check the same file before either one reaches the back office, they both think you're eligible, and both make a change. You end up changing your room number twice in one day.
    - **Evidence:**
        ```php
        // UpdateSiteAction.php — cooldown read OUTSIDE the transaction
        $site = $professional->site;
        // ...
        // This read races with concurrent requests:
        if (! $allowSubdomainOverride && $site->subdomain_changed_at) {
            $cooldownDays = (int) config('partna.handle.subdomain_cooldown_days', 30);
            $nextAllowed = $site->subdomain_changed_at->copy()->addDays($cooldownDays);
            if (Carbon::now()->lt($nextAllowed)) {
                throw ValidationException::withMessages([...]);
            }
        }
        // ... THEN the transaction starts:
        return DB::transaction(function () use (...) {
            // ...
            $data['subdomain'] = $incoming;
            $site->subdomain_changed_at = now();  // B updates this even though A just set it
            // ...
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-3** · P1 — AccountDeletionService cancel() and adminCancel() restore primary_email outside the DB transaction, producing a partial state if the transaction rolls back
    - **Where:** app/Services/User/AccountDeletionService.php — `cancel()` (around line 281) and `adminCancel()` (around line 233)
    - **Affects:** Users cancelling a pending deletion. If the transaction fails (e.g., site re-publish hits a constraint violation), their email is restored to the live value but their status remains `pending_deletion` — a torn state where the user appears deleted but has a real email.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$this->restoreEmailFromAuditSnapshot($professional)` inside the `DB::transaction()` closure in both methods, BEFORE the status flip.
        - Use `DB::connection('pgsql')->transaction()` consistently (see LIFE-4).
    - **Technical:** `restoreEmailFromAuditSnapshot()` calls `$professional->forceFill(['primary_email' => $snapshotEmail])->save()`, which immediately writes to the database. This happens before the `DB::transaction()` block that flips status from `pending_deletion` back to `active`. If the transaction fails (e.g., the site re-publish runs into an integrity constraint or a deadlock), the email is already physically restored but the status remains `pending_deletion`. The `executeConfirmation()` method, by contrast, correctly keeps `logAuditEvent()` (which snapshots the real email) and `pseudonymiseAccountPii()` (which overwrites it) inside the same transaction.
    - **Plain English:** Before officially cancelling a deletion, the system restores your email address to its real value. Then it tries to flip your account back to "active." If the second step fails, you're stuck with a real email address but an account marked "pending deletion" — like having your name put back on your mailbox but the post office still thinks you moved out.
    - **Evidence:**
        ```php
        // cancel() — email restore OUTSIDE the transaction
        public function cancel(User $professional, Request $request): array
        {
            // ...
            $this->restoreEmailFromAuditSnapshot($professional);  // WRITES primary_email immediately

            DB::transaction(function () use ($professional, $previousStatus) {
                $professional->update([
                    'status' => $previousStatus,  // may fail
                    // ...
                ]);
                // ...
            });
            // ...
        }
        ```
        ```php
        // adminCancel() — same pattern
        public function adminCancel(...): array
        {
            // ...
            $this->restoreEmailFromAuditSnapshot($professional);  // WRITES outside

            DB::transaction(function () use ($professional, $previousStatus) {
                $professional->update([...]);  // may fail
                // ...
            });
            // ...
        }
        ```
        ```php
        // restoreEmailFromAuditSnapshot — immediate DB write
        private function restoreEmailFromAuditSnapshot(User $professional): void
        {
            // ...
            if (is_string($snapshotEmail) && $snapshotEmail !== '') {
                $professional->forceFill(['primary_email' => $snapshotEmail])->save();  // IMMEDIATE
            }
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#LIFE-4** · P2 — SiteProvisioningService catches `QueryException` + matches SQLSTATE code `23505` instead of using the typed `UniqueConstraintViolationException`
    - **Where:** app/Services/User/SiteProvisioningService.php:107-114 (`tryCreateSite()` catch block) and :122-125 (`isUniqueViolation()`)
    - **Affects:** Developers maintaining the retry loop — the catch clause is fragile across database drivers and Postgres version upgrades. In production (pgsql) it works today; under SQLite tests the error code differs and the check silently fails, masking test coverage gaps.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e)` with `catch (UniqueConstraintViolationException $e)`.
        - Delete the private `isUniqueViolation()` helper method.
    - **Technical:** House doctrine requires `catch (UniqueConstraintViolationException $e)` — never `catch (QueryException $e)` + string/SQLSTATE matching. `QueryException::getCode()` returns the SQLSTATE, which is `'23505'` under Postgres for unique violations but `'23000'` under SQLite. The `UpdateSiteAction` already uses `catch (UniqueConstraintViolationException $e)` (with an inline comment acknowledging LIFE-1); `SiteProvisioningService` is the holdout.
    - **Plain English:** The code checks for a "duplicate entry" error by reading the numeric error code 23505, which is Postgres-specific. If the database ever changes or a test runs against a different database engine (like the project's own test suite which uses SQLite), the error code is different and the check doesn't work. The framework already provides a dedicated exception class for this exact error — we should use that instead.
    - **Evidence:**
        ```php
        // SiteProvisioningService.php — old pattern
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }

        private function isUniqueViolation(QueryException $e): bool
        {
            return $e->getCode() === '23505';  // Postgres-specific
        }
        ```
        ```php
        // UpdateSiteAction.php — correct pattern (already used elsewhere)
        } catch (UniqueConstraintViolationException $e) {
            // Alias row already exists — refresh lifecycle timestamps...
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#LIFE-5** · P2 — AccountDeletionService::purge() returns `false` on failure with only `Log::error` — no alert path for a user stuck in `pending_deletion` beyond their grace period
    - **Where:** app/Services/User/AccountDeletionService.php — `purge()` method (returns `false` at multiple failure points)
    - **Affects:** Users whose grace period expires but whose hard-delete keeps failing (Supabase API outage, R2 outage, forceDelete constraint issue). They remain `pending_deletion` indefinitely with no monitoring alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Track consecutive failure count per user in the deletion audit table or a dedicated column.
        - After N consecutive daily failures (e.g., 3), throw an exception so Nightwatch fires an alert.
        - Alternatively, add a health-check query in the daily purge command that alerts when any user has been `pending_deletion` > (retention_days + 7).
    - **Technical:** `purge()` is called by `PurgeSoftDeleted` (a daily console command). Every failure path — Supabase deletion failure, forceDelete exception — returns `false` and writes a `EVENT_PURGE_FAILED` audit row. But neither the audit row nor the `Log::error` calls trigger Nightwatch (Nightwatch fires on exceptions and auto-detected slow jobs/routes, not log queries). A user whose purge keeps failing sits in `pending_deletion` forever — their DB row is never cleaned up, and no human is alerted. Per doctrine, long-lived in-flight states need a "stuck for > N hours" alert path via throw or `$this->fail()`.
    - **Plain English:** When it's time to permanently delete an account, the system tries several steps (delete auth, clean up files, wipe the database). If any step fails, it logs a note and tries again tomorrow. But if tomorrow's attempt also fails, and the next day's too, there's no alarm that goes off. The account stays in limbo forever, and no one on the team knows to investigate.
    - **Evidence:**
        ```php
        // purge() — every failure path returns false with only Log::error
        public function purge(User $professional): bool
        {
            // Step 1: delete Supabase auth user
            if ($authUserId !== '' && ! $this->deleteSupabaseAuthUser($authUserId)) {
                $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_PURGE_FAILED, ...);
                return false;  // ← no throw, no alert
            }
            // ...
            try {
                $professional->forceDelete();
            } catch (\Throwable $e) {
                Log::error('Professional forceDelete failed during purge', [...]);
                // ...
                return false;  // ← no throw, no alert
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#LIFE-6** · P3 — AccountDeletionService cancel() and adminCancel() use bare `DB::transaction()` instead of `DB::connection('pgsql')->transaction()`, inconsistent with the service's own `request()` and `executeConfirmation()`
    - **Where:** app/Services/User/AccountDeletionService.php — `cancel()` (around line 298) and `adminCancel()` (around line 254)
    - **Affects:** Test reliability only — in production the default connection is `pgsql`, so the transaction works correctly. Under SQLite tests, the wrapper is a no-op, masking rollback bugs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace bare `DB::transaction(...)` with `DB::connection('pgsql')->transaction(...)` in both `cancel()` and `adminCancel()`.
    - **Technical:** The service's `request()` and `executeConfirmation()` methods use `DB::connection('pgsql')->transaction()` with an explicit comment explaining why: the default connection is `sqlite` in feature tests, making bare `DB::transaction()` a no-op. The `cancel()` and `adminCancel()` methods were missed during that hardening pass. Production behavior is correct, but tests running against SQLite won't actually wrap these operations in a transaction.
    - **Plain English:** The code for cancelling a deletion uses a different locking mechanism than the code for initiating and confirming one. In the live system they work the same because they all talk to the same database. But in the test suite, the cancel path's lock is effectively a cardboard cutout — it looks real but doesn't actually protect anything. Bugs in the cancel flow could pass tests and reach production.
    - **Evidence:**
        ```php
        // cancel() — bare transaction
        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([...]);
            // ...
        });
        ```
        ```php
        // request() — correct pattern, same file
        DB::connection('pgsql')->transaction(function () use (...) {
            $professional->update([...]);
            SendAccountDeletionRequestMailJob::dispatch(...);
            $this->logAuditEvent(...);
        });
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#LIFE-7** · P3 — SectionVisibilityService::reevaluateEnabled() catches `\Throwable` and logs a bare `Log::warning`, swallowing persistent failures invisibly
    - **Where:** app/Services/User/SectionVisibilityService.php — `reevaluateEnabled()` method (try/catch around block save)
    - **Affects:** Section visibility observers — if a dependency service (DB, Service model) consistently fails, `is_enabled` silently drifts out of sync with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `user_id`, `site_id`, and `block_type` context to the log call.
        - Consider throwing after N consecutive failures or using a dedicated exception type so Nightwatch can alert.
        - At minimum, replace `Log::warning` with `report($e)` so the exception appears in the error tracker.
    - **Technical:** `reevaluateEnabled()` is called from observers when content changes (e.g., a service is created/deleted). It re-checks visibility requirements and updates `block.is_enabled`. If the `checkVisibilityRequirements()` call or the `$block->save()` throws for a persistent reason (e.g., a misconfigured DB column), the catch swallows it with only a `Log::warning`. Nightwatch does not alert on log queries; per doctrine, soft failures that need attention must throw or `$this->fail()`. The log also lacks `user_id` context, making Nightwatch correlation impossible.
    - **Plain English:** When someone adds a photo to their gallery, the system checks whether the gallery section should now be visible. If that check crashes because of a database problem, the system quietly writes a sticky note and moves on. The sticky note isn't wired to any alarm, so no one sees it. The gallery stays hidden even though it should be visible, and the user has no idea why.
    - **Evidence:**
        ```php
        public function reevaluateEnabled(string $userId, string $siteId, string $blockType): void
        {
            // ...
            try {
                [$canBeEnabled] = $this->checkVisibilityRequirements($userId, $siteId, $blockType);
                if ((bool) $block->is_enabled !== $canBeEnabled) {
                    $block->is_enabled = $canBeEnabled;
                    $block->save();
                }
            } catch (\Throwable $e) {
                Log::warning('Section is_enabled reevaluation failed', [
                    'user_id' => $userId,
                    'site_id' => $siteId,
                    'block_type' => $blockType,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#LIFE-8** · P3 — AccountDeletionService::purge() handles cache invalidation failure with bare `Log::warning`, leaving a deleted user's sitepage cached at the edge for up to TTL with no alert
    - **Where:** app/Services/User/AccountDeletionService.php — `purge()` method (SiteCacheService call wrapped in try/catch with Log::warning)
    - **Affects:** Recently hard-deleted users — their public sitepage may remain accessible at the Cloudflare edge for up to the cache TTL (15 min), and if the cache invalidation call consistently fails (e.g., Redis down), the stale page could persist longer with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log the failure at `Log::error` level with full context (site_id, subdomain).
        - Consider retrying the invalidation once before giving up.
        - If the stale-cache window is acceptable (TTL is short), document the tradeoff explicitly.
    - **Technical:** After force-deleting a professional, `purge()` calls `app(SiteCacheService::class)->invalidateSite($site)` to bust the Cloudflare edge cache. This is wrapped in a try/catch that logs `Log::warning`. Per doctrine, `Log::warning` alone does not alert Nightwatch. While the cache TTL is short (configurable, likely 60–900s), a persistent Redis or Cloudflare API failure would leave the deleted user's page accessible indefinitely with no alert. The `Log::warning` call does not include the `subdomain` or `handle` in context, making manual investigation harder.
    - **Plain English:** After permanently deleting an account, the system tells the front-door cache to forget that person's page. If that "forget" instruction fails, the system writes a quiet note and moves on. For the next few minutes, anyone visiting that person's old web address still sees their page as if nothing happened. If the cache system is having a bad day, that window could be much longer, and no one on the team would know.
    - **Evidence:**
        ```php
        // purge() — cache invalidation failure is a whisper
        if ($site) {
            try {
                app(SiteCacheService::class)->invalidateSite($site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed during account purge', [
                    'user_id' => $professional->id,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.65]`

<!-- ═══ LENS: lifecycle-correctness | CHUNK: moderation-streaming ═══ -->

- [ ] **#LIFE-1** · P1 — Idempotency stamp placed before preconditions causes permanent loss of enquiry notification on transient failures
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:32-48 and 52-73
    - **Affects:** Content creators receiving contact‑form enquiries via email. A transient error (e.g., mail‑server hiccup, DB read failure for the contact block) silently loses the notification forever because the `email_sent_at` flag is already committed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `email_sent_at` stamp to **after** `Mail::to(…)->send(…)` succeeds, so a retry can still deliver if the send throws.
        - Keep the `lockForUpdate` + `email_sent_at = null` check inside the transaction to stop concurrent workers from double‑stamping, but do not persist the stamp until the mail is actually accepted.
    - **Technical:** The job acquires a row lock, reads `email_sent_at`, and immediately sets it to `now()` – then commits the transaction. Later it resolves the contact block and tries to send. If the block lookup fails (connection drop, deleted row) or `Mail::send` throws, the method returns without sending, but the stamp already blocks any retry. The house doctrine for idempotency requires that the success marker be written **after** the side‑effect (or at least after all preconditions are confirmed) so that transient failures trigger a retry instead of a silent skip. This is especially dangerous for a notification that is the primary way a professional learns of a new lead.
    - **Plain English:** Imagine you go to a post office and the clerk stamps your envelope as “sent” before checking whether the address is valid. If they then discover the address is missing, the stamp stays – and your letter gets thrown away without you ever knowing. This job does exactly that: it marks the enquiry email as “sent” before actually sending it. A quick mail‑server stumble means the professional never sees the enquiry, and no one retries.
    - **Evidence:**
        ```php
        // Transactions stamps email_sent_at BEFORE any further checks
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            // …
            if ($e->email_sent_at !== null) { return false; }
            $e->forceFill(['email_sent_at' => now()])->saveQuietly();
            return $e;
        });
        // Later, resolve block and send — if any of these fail, stamp is already set
        $block = Block::query()
            ->whereKey($this->blockId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
        if ($block === null) { /* no block -> no email, but stamp already committed */ return; }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-2** · P1 — Idempotency stamp placed before preconditions causes permanent loss of visitor confirmation email on transient failures
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:33-52 and 56-74
    - **Affects:** Visitors who submit a contact form – they may never receive the “we got your enquiry” confirmation if a transient error occurs after the stamp.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `confirmation_sent_at` stamp to **after** the `Mail::to(…)->send(…)` succeeds, mirroring the fix for `SendEnquiryNotificationJob`.
        - Keep the lock‑and‑null check inside the transaction but defer the actual write until the mail has been handed off successfully.
    - **Technical:** Identical pattern: the job locks the enquiry row, stamps `confirmation_sent_at`, commits, and **then** verifies the recipient email, block settings, and rate limit. If the block or the mailer fails, the stamp remains. Retries will see the stamp and skip, permanently dropping the confirmation. For a user‑facing transactional email, dropping a confirmation is a poor experience; the cost of a double‑send is negligible compared to a missing one.
    - **Plain English:** Same post‑office example, but now it’s the visitor – they submit the form, get a “thank you” promise, and the system stamps “sent” before checking if there’s an actual letter to deliver. A brief glitch means they never see the confirmation, and no one tries again.
    - **Evidence:**
        ```php
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            // …
            if ($e->confirmation_sent_at !== null) { return false; }
            $e->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
            return $e;
        });
        // … later, resolve block, check send_visitor_confirmation, rate limit, etc.
        // If any of those checks return early, stamp is already committed.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-3** · P1 — Idempotency stamp placed before preconditions causes permanent loss of subscription confirmation email on transient failures
    - **Where:** app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:43-55 and 87-95
    - **Affects:** Newsletter subscribers – they may never receive the “you’re subscribed” confirmation if a transient error occurs after the stamp.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `confirmation_sent_at` stamp to **after** the `Mail::to(…)->send(…)` succeeds.
        - Keep the lock‑and‑null check inside the transaction, but defer the actual write until the mail has been dispatched.
    - **Technical:** Same structural flaw as the enquiry‑related jobs. The subscription row is stamped before the block toggle is read and the mail is sent. A transient failure in the subsequent steps (e.g., block not found, mailer timeout) leaves the stamp set, and the retry will silently skip, causing a subscriber to never receive the confirmation. For email‑based newsletter signups, this can look like a broken sign‑up process and drive complaints.
    - **Plain English:** The post‑office stamps your subscription confirmation letter as “sent” before verifying that your subscription is still active. A small error means you never get the confirmation, and the system assumes it already delivered it – so you never get a second try.
    - **Evidence:**
        ```php
        $sub = DB::transaction(function () {
            $s = EmailSubscription::query()->lockForUpdate()->find($this->subscriptionId);
            // …
            if ($s->confirmation_sent_at !== null) { return false; }
            $s->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
            return $s;
        });
        // … later, check block toggle, rate limit, and send.
        // Early returns after the stamp always lose the confirmation.
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ LENS: lifecycle-correctness | CHUNK: connectors ═══ -->

- [ ] **#LIFE-1** · P1 — InstagramConnectJob missing ShouldBeUnique allows duplicate costly Apify scrapes
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:32 (class declaration)
    - **Affects:** Billed Apify usage; duplicate R2 writes; user sees a stale "pending" status while two jobs race
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ShouldBeUnique` to the `implements` clause.
        - Add `uniqueId()` returning `$this->connectionId` so a second dispatch for the same connection coalesces within the window.
        - Add `public int $uniqueFor = 300;` to match the existing `DeleteMirroredMediaJob` pattern.
    - **Technical:** The existing `DeleteMirroredMediaJob` already implements `ShouldBeUnique` with a coalesce window, proving the team knows this pattern. `InstagramConnectJob` performs a billed Apify call (up to 110s) followed by R2 writes — two concurrent dispatches of the same job mean two billed scrapes and two parallel R2 writes racing to update the same connection row. A `uniqueFor` window matching the job's max duration (~150s) prevents the second dispatch entirely if the first is still running.
    - **Plain English:** Imagine ordering the same Uber twice because the app didn't show the first one was already on the way. You'd pay for two cars. That's what happens if the connect job gets queued twice — two paid Apify API calls fire, both upload photos to storage, and the second one overwrites the first's work. The fix is a simple "don't send a second one while the first is still in progress" guard that already exists on a nearby cleanup job.
    - **Evidence:**
        ```php
        class InstagramConnectJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            // Apify can take up to 110s; allow headroom for image mirroring on top.
            public int $timeout = 150;

            public int $tries = 2;
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-2** · P2 — GoogleBusinessService::streetViewPano swallows all exceptions silently
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:287-294
    - **Affects:** Nightwatch alerting; operators debugging Google API key misconfiguration
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Log::warning` call with `placeId`, `lat`, `lng`, and the exception message before returning null.
        - Alternatively, let the exception propagate and handle it in the caller (`fetchPlaceDetails`) which already has a catch block.
    - **Technical:** The catch block is `catch (\Throwable) { return null; }` — no log, no `$this->fail()`, no re-throw. If Google's Street View metadata endpoint starts returning errors (billing disabled, API key revoked, per-IP quota exhaustion), this path is completely invisible to Nightwatch. The caller (`fetchPlaceDetails`) treats a null return as "no Street View pano available at this pin" and continues silently. A persistent API failure will never alert. Per the observability doctrine, path-constructed vendor URLs like `maps.googleapis.com/maps/api/streetview/metadata` are not user-supplied, so `SafeUrlFetcher` is not required, but the failure must still be observable.
    - **Plain English:** There's a free "check if Street View exists at this location" probe. If Google's server is down or the API key is broken, the code silently says "nope, no Street View here" instead of "something's wrong." Over time, a broken API key could go unnoticed because the failure looks identical to a location that genuinely lacks Street View coverage. One log line fixes it.
    - **Evidence:**
        ```php
        try {
            $res = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/streetview/metadata', [
                'location' => $lat.','.$lng,
                'radius' => 100,
                'source' => 'outdoor',
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-3** · P2 — GoogleBusinessService::resolvePhotoUrls logs warning without placeId context
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:254-258
    - **Affects:** Nightwatch correlation; operators debugging photo resolution failures
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'placeId'` to the log context array. The caller (`fetchPlaceDetails`) has `$placeId` in scope — pass it through or derive it from the photo refs.
    - **Technical:** Per the `Log-with-context` pattern, every `Log::warning` must carry enough correlation keys (`user_id`, `request_id`, operation name) so Nightwatch can group and attribute. The exception catch here logs `'google_business.photo_resolve_failed'` with only `'message' => $e->getMessage()` — no `placeId`. When this fires at scale with thousands of places being refreshed, all failures are indistinguishable. The `fetchPlaceDetails` caller has `$placeId` but `resolvePhotoUrls` is a private method that doesn't receive it, so the context is lost at the boundary.
    - **Plain English:** If photo downloads start failing for a particular business listing, the error log says "photo download failed" but doesn't say WHICH business. You'd have to guess. Adding the place ID takes one line and lets you instantly find the problem listing.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('google_business.photo_resolve_failed', ['message' => $e->getMessage()]);
            return $photos;
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#LIFE-4** · P3 — PlatformRefresher read-modify-write race on consecutive_failures counter
    - **Where:** app/Services/Platforms/PlatformRefresher.php:76-78 and :86-88
    - **Affects:** Accuracy of the `consecutive_failures` monitoring counter under concurrent refresh
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `$connection->increment('consecutive_failures')` instead of `(int) $connection->consecutive_failures + 1` followed by `saveQuietly()`.
        - Alternatively, wrap the read + write in `lockForUpdate` at the point the model is fetched.
    - **Technical:** The failure path reads the model's `consecutive_failures` (loaded at the top of `refresh()`) and writes `value + 1`. With two concurrent refresh calls for the same connection (e.g., manual "Refresh now" overlapping the daily cron), both read the same stale value and both write the same incremented result, losing one increment. An `increment()` call is atomic in Postgres and avoids the race entirely. The impact is minor — this is an observability counter, not a correctness field — but at thousands of users the cumulative masking of persistent failures could delay operator response.
    - **Plain English:** Two people hit "refresh" at the same time on the same connection. The failure counter should go up by 2 if both fail. Instead it goes up by 1 because both read "0" before either writes. It's like two people each depositing $1 into a bank account at the same time but the balance only goes up by $1. For a counter that helps detect "this connection has been failing for 5 days straight," missing one increment is minor but easy to fix with an atomic increment.
    - **Evidence:**
        ```php
        $connection->forceFill([
            'last_refresh_status' => $status,
            'last_refresh_error' => $error,
            'consecutive_failures' => (int) $connection->consecutive_failures + 1,
        ])->saveQuietly();
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#LIFE-5** · P3 — SmartLinkRefresher read-modify-write race on consecutive_failures counter
    - **Where:** app/Services/SmartLinks/SmartLinkRefresher.php:31-35
    - **Affects:** Same as LIFE-4 but for SmartLink refresh path
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `$link->increment('consecutive_failures')` on the failure path.
    - **Technical:** Same pattern as PlatformRefresher: the failure path reads `$link->consecutive_failures` (loaded before the re-resolve) and writes `+1` via `forceFill()->save()`. Manual "Refresh now" and the `smartlinks:refresh` cron can race. In practice, manual refreshes from the dashboard are rare and the consequence (lost increment) is minor, but the fix is trivial.
    - **Plain English:** Same bank-account analogy as the platform refresher — two simultaneous refresh attempts on the same smart link both read the old failure count and both write the same "old + 1" value, losing one increment. Low impact, high fix simplicity.
    - **Evidence:**
        ```php
        $link->forceFill([
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'unavailable',
            'consecutive_failures' => (int) $link->consecutive_failures + 1,
        ])->save();
        ```
    - `[DRAFT, confidence: 0.75]`
