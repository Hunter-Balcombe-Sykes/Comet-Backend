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
