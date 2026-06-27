# Lifecycle Correctness Audit — 2026-06-13

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/User/DataExport/DataExportService.php`
- `app/Jobs/Gdpr/ExportUserDataJob.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Services/Site/UpdateSiteAction.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/SectionVisibilityService.php`
- `app/Jobs/Account/SendAccountDeletionRequestMailJob.php`
- `app/Jobs/Notifications/SendEnquiryNotificationJob.php`
- `app/Jobs/Notifications/SendEnquiryConfirmationJob.php`
- `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Services/Platforms/GoogleBusinessService.php`
- `app/Services/Platforms/PlatformRefresher.php`
- `app/Services/SmartLinks/SmartLinkRefresher.php`

**Adjudication notes — dropped findings:**
- **Moderation-streaming LIFE-1/2/3** (enquiry + subscription notification stamp-before-send): dropped. Each job carries an explicit `// Deliberate at-most-once choice:` comment documenting the tradeoff; the stamp under `lockForUpdate` before the mail send is intentional, not a bug.
- **Account-site LIFE-8** (purge cache invalidation Log::warning): dropped — confidence 0.65, below threshold for a non-security finding; TTL is short and the deletion path already has correct audit coverage.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 6 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — `InstagramConnectJob` missing `ShouldBeUnique` causes double-billed Apify scrapes on retries
    - **Where:** `app/Jobs/Platforms/InstagramConnectJob.php:31`
    - **Affects:** Any user who double-taps "Connect Instagram", any network retry from the frontend, or any Horizon worker restart mid-dispatch — two copies of the same job queue up, both call the Apify scrape API (a billed, up-to-110s call), both mirror up to 9 images to R2, and both write the connection row. Category 1 / pattern: `ShouldBeUnique`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ShouldBeUnique` to the `implements` clause of `InstagramConnectJob`.
        - Add `public function uniqueId(): string { return $this->connectionId; }`.
        - Add `public int $uniqueFor = 180;` (covers the 150s job timeout with headroom).
    - **Technical:** `DeleteMirroredMediaJob` in the same namespace already implements `ShouldBeUnique` with `uniqueId()` keyed on a connection identifier — the pattern is established. `InstagramConnectJob` is more expensive per run (billed Apify call + parallel R2 writes) and has a 150s timeout, making duplicate execution both financially and operationally costly. Without the uniqueness guard, a duplicate dispatch within the `$uniqueFor` window queues silently and a second worker picks it up. The second worker updates `payload`, `last_refreshed_at`, and `consecutive_failures` concurrently with the first, producing a torn final state for the connection row and billing two Apify calls. The `$uniqueFor` window should be ≥ `$timeout` (150s); 180s gives safe headroom.
    - **Plain English:** Imagine hiring a contractor to install a new kitchen. If you accidentally send two work orders for the same job, you pay twice and they fight over who installs the sink. Right now, if "Connect Instagram" gets clicked twice or a network retry fires, the system sends two workers to scrape and download the same Instagram profile. You're billed twice for the scrape and the two workers might clobber each other's results. The fix is a simple "only send one worker at a time per account" rule that a nearby job already uses.
    - **Evidence:**
        ```php
        // InstagramConnectJob.php — no ShouldBeUnique
        class InstagramConnectJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $timeout = 150;
            public int $tries = 2;
        ```
        ```php
        // DeleteMirroredMediaJob.php — the established pattern in the same namespace
        class DeleteMirroredMediaJob implements ShouldBeUnique, ShouldQueue
        ```

- [ ] **#LIFE-2** · P1 — `cancel()` and `adminCancel()` restore primary email outside the database transaction, producing torn state if the transaction rolls back
    - **Where:** `app/Services/User/AccountDeletionService.php` — `cancel()` (line 428) and `adminCancel()` (line 355)
    - **Affects:** Users or admins cancelling a pending-deletion during the 30-day grace period. If the `DB::transaction()` fails (e.g., site re-publish hits a deadlock or constraint error), `primary_email` is already physically restored to the real value while `status` stays `pending_deletion`. The user has their real email re-exposed in a state that the app treats as "deletion scheduled." Category 3 / pattern: `DB::afterCommit` (or move write inside transaction).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$this->restoreEmailFromAuditSnapshot($professional)` to INSIDE the `DB::connection('pgsql')->transaction()` closure in both `cancel()` and `adminCancel()`, as the first statement before the status flip.
        - While there: replace bare `DB::transaction()` with `DB::connection('pgsql')->transaction()` in both methods for consistency (see LIFE-7).
    - **Technical:** `restoreEmailFromAuditSnapshot()` calls `$professional->forceFill(['primary_email' => $snapshotEmail])->save()` — an immediate DB write that happens before the transaction begins. The transaction then flips `status`, re-publishes the site with `lockForUpdate`, and can fail. On failure, the email write is not rolled back (it was never inside a transaction); the status remains `pending_deletion`. The `executeConfirmation()` method avoids this correctly: `logAuditEvent` (which reads the live email as a snapshot) and `pseudonymiseAccountPii` (which overwrites it) are both inside the same transaction. The fix is to mirror that ordering: all mutating writes in one atomic block.
    - **Plain English:** When a user cancels their scheduled deletion, the system puts their real email address back into the database, then separately tries to flip their account back to "active." If the second step fails because of a database hiccup, their real email is already in the system but their account still says "being deleted." It's like putting your name back on the office door before the HR paperwork goes through — if HR's system crashes, your name is on the door but you're technically still terminated. The fix is to do both changes at the same time, so if either fails, neither sticks.
    - **Evidence:**
        ```php
        // cancel() — email restore fires BEFORE the transaction opens
        $this->restoreEmailFromAuditSnapshot($professional);  // ← immediate DB write, line 428

        DB::transaction(function () use ($professional, $previousStatus) {  // ← line 430, may throw
            $professional->update(['status' => $previousStatus, ...]);
            $site = Site::query()->where('user_id', $professional->id)->lockForUpdate()->first();
            if ($site && $site->unpublished_at !== null) {
                $site->update(['is_published' => true, 'unpublished_at' => null]);
            }
        });
        ```
        ```php
        // restoreEmailFromAuditSnapshot — immediate physical write
        private function restoreEmailFromAuditSnapshot(User $professional): void
        {
            // ...
            if (is_string($snapshotEmail) && $snapshotEmail !== '') {
                $professional->forceFill(['primary_email' => $snapshotEmail])->save();
            }
        }
        ```
        ```php
        // executeConfirmation() — correct: all writes inside one transaction
        DB::connection('pgsql')->transaction(function () use ($professional, ...) {
            $professional->update(['status' => 'pending_deletion', ...]);
            // ...
            $this->logAuditEvent($professional, ...);  // reads live email
            $this->pseudonymiseAccountPii($professional);  // overwrites it
        });
        ```

- [ ] **#LIFE-3** · P1 — `ExportUserDataJob` dispatched inside a DB transaction without `$this->afterCommit = true`, silently losing GDPR data exports on a fast worker pickup
    - **Where:** `app/Services/User/DataExport/DataExportService.php:59` (dispatch); `app/Jobs/Gdpr/ExportUserDataJob.php:31–34` (constructor missing property)
    - **Affects:** Users and staff triggering GDPR data exports. A fast Horizon worker can dequeue the job before `DB::connection('pgsql')->transaction()` commits the `DataExportAudit` row. The job then finds `null` from `DataExportAudit::find($this->auditId)`, logs a warning, and `return`s — no exception, no retry, the export is permanently lost. Category 1 / pattern: `DB::afterCommit`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->afterCommit = true;` to `ExportUserDataJob`'s constructor (same pattern as `SendAccountDeletionRequestMailJob`, which carries an explicit comment explaining why).
        - Change the null-audit branch in `handle()` from `return` to `throw new \RuntimeException(...)` so that if the row is still missing after a retry window it surfaces to Nightwatch rather than disappearing silently.
    - **Technical:** `DataExportService::dispatch()` wraps the audit-row insert and job dispatch in one `DB::connection('pgsql')->transaction()` (line 35). Laravel releases the Redis job payload at dispatch time, not at commit time. Without `$this->afterCommit = true`, a worker that picks up the job before the transaction commits finds no audit row (`DataExportAudit::find()` returns `null`) and silently returns — `$tries = 3` and `$backoff = [60, 300, 900]` are never exercised because no exception is thrown. `SendAccountDeletionRequestMailJob`, dispatched by the same service in a different method, already sets `$this->afterCommit = true` with a comment explaining exactly this. `ExportUserDataJob` is the holdout.
    - **Plain English:** When someone requests a download of all their data (a legal right), the system creates a record of the request and immediately queues a worker to build the download. The problem is the worker can start looking for that record before the record is actually saved. If that happens, the worker shrugs and quits — no download is ever created, no one is alerted, and the user is left waiting indefinitely. The fix is a one-line instruction telling the worker "wait until the record is safely saved before you start." A nearly identical job in the same codebase already has this instruction — it just needs to be copied across.
    - **Evidence:**
        ```php
        // DataExportService.php — job dispatched inside transaction, no afterCommit
        return DB::connection('pgsql')->transaction(function () use (...) {
            // ...
            $audit = DataExportAudit::create([...]);
            ExportUserDataJob::dispatch($audit->id);  // worker can race the commit
            return $audit;
        });
        ```
        ```php
        // ExportUserDataJob.php constructor — $this->afterCommit = true is absent
        public function __construct(public string $auditId)
        {
            $this->onQueue(config('partna.gdpr.queue'));
            // $this->afterCommit = true;  ← missing
        }
        ```
        ```php
        // ExportUserDataJob.php handle() — null audit silently returns, no retry
        $audit = DataExportAudit::find($this->auditId);
        if (! $audit) {
            Log::warning('ExportUserDataJob: audit row not found', ['audit_id' => $this->auditId]);
            return;  // no throw → $tries/$backoff never engaged
        }
        ```
        ```php
        // SendAccountDeletionRequestMailJob.php — the established pattern, same service
        $this->afterCommit = true;  // Set on the instance … see comment in constructor
        ```

---

## P2 — Should fix

- [ ] **#LIFE-4** · P2 — `SiteProvisioningService` catches `QueryException` and SQLSTATE-matches `'23505'` instead of using the typed `UniqueConstraintViolationException`
    - **Where:** `app/Services/User/SiteProvisioningService.php:115–130`
    - **Affects:** Maintainers and test coverage. The `getCode()` SQLSTATE check returns `'23505'` under Postgres but `'23000'` under SQLite, so tests exercising the retry loop in the SQLite test suite miss this branch entirely. If the error code format changes across a Postgres or driver upgrade, the catch silently re-throws instead of treating it as a retryable collision. Category 4 / pattern: `UniqueConstraintViolationException`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e)` + `$this->isUniqueViolation($e)` with `catch (UniqueConstraintViolationException $e)` directly.
        - Delete the private `isUniqueViolation()` helper.
        - Add `use Illuminate\Database\UniqueConstraintViolationException;` (already imported in `UpdateSiteAction.php`).
        - The SAVEPOINT semantics are unaffected — `UniqueConstraintViolationException` extends `QueryException` and is caught at the same point in the call stack.
    - **Technical:** House doctrine requires the typed exception class, never `QueryException` + SQLSTATE string comparison. The code's own comment at line 88–100 explains the SAVEPOINT rationale but that reasoning applies equally to the typed catch. `UpdateSiteAction.php` already uses `catch (UniqueConstraintViolationException $e)` with an inline comment acknowledging this preference. The SQLSTATE `'23505'` string is Postgres-specific and fragile; `UniqueConstraintViolationException` is resolved by the PDO layer regardless of driver, making test-suite coverage accurate and upgrade-safe.
    - **Plain English:** The code detects a "that name is already taken" error by checking a Postgres-specific numeric error code. It works in production, but the test suite uses a different database that gives a different number, so the error-handling logic is never tested. There's a purpose-built error type in the framework that works the same way on all databases — using it is a one-line change that makes the tests trustworthy.
    - **Evidence:**
        ```php
        // SiteProvisioningService.php — fragile SQLSTATE check
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }

        private function isUniqueViolation(QueryException $e): bool
        {
            return $e->getCode() === '23505';  // Postgres-specific; differs under SQLite
        }
        ```
        ```php
        // UpdateSiteAction.php — the correct house-doctrine pattern, same codebase
        } catch (UniqueConstraintViolationException $e) {
            // Alias row already exists — refresh lifecycle timestamps…
        }
        ```

- [ ] **#LIFE-5** · P2 — `UpdateSiteAction` reads `subdomain_changed_at` from a stale pre-transaction `$site` snapshot, allowing two concurrent rename requests to both bypass the 30-day cooldown
    - **Where:** `app/Services/Site/UpdateSiteAction.php:28–103`
    - **Affects:** Any user submitting two simultaneous subdomain rename requests (e.g., a double-submit from slow UI, or two open browser tabs). Both read the same stale `$site->subdomain_changed_at`, both pass the cooldown check, and both commit — allowing two renames within the 30-day window. At thousands of users some will discover the double-submit window. Category 2 / pattern: `lockForUpdate`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Inside the `DB::transaction()` closure, before reading `$site->subdomain_changed_at`, add a `lockForUpdate` refresh: `$site = Site::query()->lockForUpdate()->find($site->id);` and re-derive `$current` from the fresh row.
        - This serialises concurrent rename transactions through the row lock and ensures only the winner can proceed.
    - **Technical:** `$professional->loadMissing('site')` and `$site = $professional->site` fire at lines 28–30, before `DB::transaction()` opens at line 85. The closure captures `$site` by reference (via `use`). Inside the transaction, line 93 checks `$site->subdomain_changed_at` — but this is the value snapshotted before the transaction began. Two concurrent transactions both capture the same stale value, both pass the check, and both execute the rename. The final `UNIQUE` constraint on `subdomain` only catches two renames to the same target; renames to different targets both succeed. This is a TOCTOU race on the cooldown field, the same pattern that `DB::transaction()` + `lockForUpdate` was introduced to solve on alias rows in `ReclaimHandleAction`.
    - **Plain English:** Imagine a "change your company name" form has a 30-day waiting period. Before checking whether you're allowed to change, the system reads your current name from a notepad it wrote an hour ago. If two people hit "submit" at exactly the same moment, they both check the same old notepad, both see you're within the waiting period, and both let the change go through — bypassing the waiting period entirely. The fix is to read your current name directly from the authoritative file cabinet while no one else can touch it, not from a stale copy.
    - **Evidence:**
        ```php
        // Lines 28-30 — $site loaded BEFORE the transaction, stale snapshot
        $professional->loadMissing('site');
        $site = $professional->site;

        // ...pre-transaction validation...

        // Line 85 — transaction opens; $site is a captured stale object
        return DB::transaction(function () use ($professional, $site, $data, $options, $allowSubdomainOverride): Site {
            if (array_key_exists('subdomain', $data)) {
                $incoming = strtolower($data['subdomain']);
                $current = strtolower((string) $site->subdomain);
                // ...
                if (! $allowSubdomainOverride && $site->subdomain_changed_at) {
                    // reads stale snapshot — no SELECT … FOR UPDATE before this check
                    $cooldownDays = (int) config('partna.handle.subdomain_cooldown_days', 30);
                    $nextAllowed = $site->subdomain_changed_at->copy()->addDays($cooldownDays);
                    if (Carbon::now()->lt($nextAllowed)) {
                        throw ValidationException::withMessages([...]);
                    }
                }
        ```

- [ ] **#LIFE-6** · P2 — `AccountDeletionService::purge()` returns `false` on every failure path with only `Log::error`, leaving users stuck in `pending_deletion` indefinitely with no Nightwatch alert
    - **Where:** `app/Services/User/AccountDeletionService.php` — `purge()` method (lines 488–556)
    - **Affects:** Users whose daily purge fails repeatedly (Supabase Admin API outage, `forceDelete` constraint failure). They remain in `pending_deletion` beyond their grace period with no operator alert. Per doctrine, long-lived in-flight states need a visible failure path. Category 4 / pattern: `Log-with-context` + escalation after N consecutive daily failures.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In the `PurgeSoftDeleted` console command that calls `purge()`, count consecutive `false` returns per user and `throw` (or `Log::error` + `report()`) after N failures (e.g., 3 consecutive days) so Nightwatch fires.
        - Alternatively, add a health-check query at the start of the command: users with `status = 'pending_deletion'` AND `deletion_confirmed_at < now() - (retention_days + 7 days)` indicate a stuck purge — log at `error` level with `user_id` so Nightwatch groups them.
    - **Technical:** `purge()` is called by the `PurgeSoftDeleted` console command on a daily schedule. Every failure path — Supabase deletion failure (line 488), `forceDelete` exception (line 542) — logs with `Log::error` and returns `false`. Nightwatch alerts on exceptions and auto-detected slow jobs/routes, NOT on log queries. A user whose purge fails daily for a week accumulates `EVENT_PURGE_FAILED` audit rows but generates no Nightwatch alert. The audit rows are visible only to someone actively querying the DB. At thousands of users with a single daily purge run, persistent failures will accumulate silently.
    - **Plain English:** When it's time to permanently delete an account, the system runs through a checklist. If any step fails, it writes a note in a log and tries again tomorrow. But if it fails again tomorrow, and the day after, there's no alarm — the team has no idea this person's account is stuck in limbo. The notes pile up in the database, but no one is automatically told to investigate. Adding a simple "if this has failed 3 days in a row, send an alert" rule turns a silent failure into an actionable alert.
    - **Evidence:**
        ```php
        // purge() — Supabase deletion failure: returns false with no throw
        if ($authUserId !== '' && ! $this->deleteSupabaseAuthUser($authUserId)) {
            $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_PURGE_FAILED, null,
                ['reason' => 'supabase_deletion_failed'], ...);
            return false;  // ← no exception → Nightwatch never alerts
        }

        // purge() — forceDelete failure: same pattern
        try {
            $professional->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Professional forceDelete failed during purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
            $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_PURGE_FAILED, ...);
            return false;  // ← no exception → Nightwatch never alerts
        }
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-7** · P3 — `cancel()` and `adminCancel()` use bare `DB::transaction()` instead of `DB::connection('pgsql')->transaction()`, breaking transaction semantics in the SQLite test suite
    - **Where:** `app/Services/User/AccountDeletionService.php:430` (`cancel()`), `app/Services/User/AccountDeletionService.php:357` (`adminCancel()`)
    - **Affects:** Test reliability only — production uses `pgsql` as the default connection, so behaviour is correct there. In the SQLite feature test suite, `DB::transaction()` targets the SQLite connection; rollback semantics for the cancel path are never exercised. Category 2 / pattern: `DB::connection('pgsql')->transaction()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `DB::transaction(...)` with `DB::connection('pgsql')->transaction(...)` in both `cancel()` and `adminCancel()`.
    - **Technical:** `request()` and `executeConfirmation()` in the same class already use `DB::connection('pgsql')->transaction()`, with an inline comment explaining that bare `DB::transaction()` targets `sqlite` in the feature-test environment, making the wrapper a no-op. `cancel()` and `adminCancel()` were missed during that hardening pass. The gap means test scenarios that exercise cancel-path rollback (e.g., testing that a torn email state can't occur) cannot be written reliably without this fix.
    - **Plain English:** The cancellation code uses a slightly different locking mechanism than the other deletion methods in the same file. In the live system both approaches work the same way. In the automated tests, the cancellation lock is a no-op — it looks active but doesn't actually guarantee anything. Any tests written to verify cancellation safety will silently pass even if the underlying logic is broken. It's a two-word fix that makes the tests trustworthy.
    - **Evidence:**
        ```php
        // cancel() — bare DB::transaction (line 430)
        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update(['status' => $previousStatus, ...]);
            // ...
        });
        ```
        ```php
        // request() in the same class — correct pattern, with explanation comment
        // Pin the transaction to 'pgsql' explicitly so it shares the connection …
        // Using bare DB::transaction() would target the default connection, which
        // is 'sqlite' in feature tests — making the wrapper a no-op and breaking rollback.
        DB::connection('pgsql')->transaction(function () use (...) {
        ```

- [ ] **#LIFE-8** · P3 — `SectionVisibilityService::reevaluateEnabled()` swallows `\Throwable` with only `Log::warning`, making persistent `is_enabled` drift invisible to Nightwatch
    - **Where:** `app/Services/User/SectionVisibilityService.php:392–406`
    - **Affects:** Section visibility observers — if the DB or a model dependency consistently fails, `is_enabled` silently drifts out of sync (gallery stays hidden even after photos are uploaded) with no operator alert. Category 10 / pattern: `Log-with-context` (add `report($e)` so exception appears in Nightwatch).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` before (or instead of) the `Log::warning` call so persistent exceptions surface in Nightwatch.
        - The existing contextual fields (`user_id`, `site_id`, `block_type`) are adequate for correlation — no other log changes needed.
    - **Technical:** Nightwatch alerts on exceptions and auto-detected slow routes/jobs. `Log::warning` alone is a breadcrumb that requires active log querying to notice. `report($e)` forwards the exception to the configured error handler (Nightwatch in production) without re-throwing, which is the right balance for an observer side-effect: it keeps the primary write path from failing while still alerting on persistent subsidiary failures. This method is called synchronously from observers on every relevant model save, so a persistent DB column issue would fire on every save and flood logs — making Nightwatch alerting even more important for triage.
    - **Plain English:** When the gallery section's visibility is recalculated (e.g., after a photo is uploaded), if the calculation crashes for a persistent reason, the system quietly jots a sticky note and moves on. The sticky note isn't wired to any alarm — it's like a smoke detector that writes a text file instead of making noise. One extra line changes it so the failure shows up in the team's alert dashboard. The section stays in whatever state it was in, but now the team knows something is wrong.
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
                Log::warning('Section is_enabled reevaluation failed', [  // no report($e) → Nightwatch silent
                    'user_id' => $userId,
                    'site_id' => $siteId,
                    'block_type' => $blockType,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        ```

- [ ] **#LIFE-9** · P3 — `GoogleBusinessService::streetViewPano()` catches `\Throwable` with no log, making Google API key and billing failures completely invisible
    - **Where:** `app/Services/Platforms/GoogleBusinessService.php:355–357`
    - **Affects:** Platform operators diagnosing Google API misconfiguration (revoked key, disabled Street View Static API, billing cutoff). A total failure of the probe looks identical to "no outdoor pano at this pin." Category 10 / pattern: `Log-with-context`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the empty catch with a `Log::warning` that includes `lat`, `lng`, and `$e->getMessage()` so network/API failures are distinguishable from "no pano available."
    - **Technical:** The Street View metadata probe (`maps.googleapis.com/maps/api/streetview/metadata`) is a network call within `fetchPlaceDetails`. The HTTP client throws on connection failure, DNS failure, or TLS error. The caller treats a `null` return as "no Street View at this location" — there is no distinction between a probe that succeeded and returned `status != 'OK'` and a probe that never reached Google. At thousands of Google Business connections refreshed daily, a billing outage would silently strip Street View data from all sites without a single log line. The URL is not user-supplied so `SafeUrlFetcher` is not required, but the call must be observable.
    - **Plain English:** There's a quick check the system does to see if Street View photos exist for a business location. If Google's server is down, or the API key is over budget, the check quietly returns "no Street View here" — the same answer it gives when Street View genuinely doesn't exist. No alarm fires, and over time every business loses its Street View photos without anyone noticing why. Adding one line that says "failed to reach Google (reason: X)" would make it immediately obvious whether it's a real absence or a connection problem.
    - **Evidence:**
        ```php
        private function streetViewPano(string $key, float $lat, float $lng): ?array
        {
            try {
                $res = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/streetview/metadata', [
                    'location' => $lat.','.$lng,
                    'radius' => 100,
                    'source' => 'outdoor',
                    'key' => $key,
                ]);
            } catch (\Throwable) {
                return null;  // ← no log: network/billing failures are invisible
            }
        ```

- [ ] **#LIFE-10** · P3 — `GoogleBusinessService::resolvePhotoUrls()` logs `photo_resolve_failed` without the `placeId`, making all photo-resolve failures indistinguishable in Nightwatch
    - **Where:** `app/Services/Platforms/GoogleBusinessService.php:322–326`
    - **Affects:** Platform operators debugging photo resolution failures. With hundreds of Google Business connections, all failures look identical in Nightwatch — no way to find the problematic listing. Category 10 / pattern: `Log-with-context`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Thread `$placeId` as a parameter into `resolvePhotoUrls(string $key, string $placeId, array $photos)`.
        - Update the `Log::warning` call to include `'place_id' => $placeId`.
        - Update the two call sites in `fetchPlaceDetails()` that call `resolvePhotoUrls`.
    - **Technical:** `resolvePhotoUrls` is a private method called by `fetchPlaceDetails`, which already has `$placeId` in scope. The `Http::pool` call can throw on connection failure or timeout. The catch logs `google_business.photo_resolve_failed` with only `$e->getMessage()`. At scale, when multiple Google Business connections are refreshed concurrently on the daily cron, all failures emit the same log line. Per the `Log-with-context` pattern, every warning must carry enough correlation keys to attribute in Nightwatch. Adding `placeId` is a three-line change.
    - **Plain English:** When downloading business photos from Google fails, the system's error note just says "photo download failed" — with no clue about which business. If 50 businesses have this problem, the log shows 50 identical entries. Adding the business ID to each entry takes a single line and immediately makes the problem traceable. It's like a doctor writing "patient had an allergic reaction" without the patient's name on the file.
    - **Evidence:**
        ```php
        private function resolvePhotoUrls(string $key, array $photos): array
        {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(...));
            } catch (\Throwable $e) {
                Log::warning('google_business.photo_resolve_failed', ['message' => $e->getMessage()]);
                // ↑ no placeId — all failures indistinguishable at scale
                return $photos;
            }
        ```

- [ ] **#LIFE-11** · P3 — `PlatformRefresher` read-modify-write on `consecutive_failures` counter loses increments under concurrent refreshes
    - **Where:** `app/Services/Platforms/PlatformRefresher.php:92`
    - **Affects:** The observability counter used to detect persistently failing platform integrations. Two concurrent refreshes of the same connection (cron + manual "Refresh now") can both read the same stale value and both write `value + 1`, losing one increment. At thousands of users this makes the "five consecutive failures" threshold unreliable. Category 2 / pattern: `lockForUpdate` (or atomic `increment()`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'consecutive_failures' => (int) $connection->consecutive_failures + 1` with `$connection->increment('consecutive_failures')` on its own line (then use `saveQuietly()` for the other fields separately, or use `DB::raw('consecutive_failures + 1')` in the `forceFill` array).
    - **Technical:** `$connection->consecutive_failures` is read when the model is hydrated at the top of `refresh()`, before any lock. Two concurrent calls both read the same value (e.g., 2) and both write 3, instead of 2→3→4. The consequence is minor — this is an observability counter, not a correctness field — but at thousands of users with daily cron + on-demand refreshes, a user whose integration has been failing for a week might show `consecutive_failures = 4` instead of 7, potentially delaying operator response. The identical pattern exists in `SmartLinkRefresher` (LIFE-12) and `InstagramConnectJob::markFailed()`.
    - **Plain English:** When a platform integration fails, the system increments a "number of consecutive failures" counter to detect integrations that are permanently broken. If two background processes both try to increment the counter at the exact same moment, they both read the current number, both add 1, and both write the same answer — so the counter only goes up by 1 instead of 2. It's like two people each trying to add a tally mark to the same whiteboard without looking at each other first. A simple atomic increment operation fixes this.
    - **Evidence:**
        ```php
        // PlatformRefresher.php — non-atomic read-modify-write
        $connection->forceFill([
            'last_refresh_status' => $status,
            'last_refresh_error' => $error,
            'consecutive_failures' => (int) $connection->consecutive_failures + 1,  // stale read
        ])->saveQuietly();
        ```

- [ ] **#LIFE-12** · P3 — `SmartLinkRefresher` read-modify-write on `consecutive_failures` counter loses increments under concurrent refreshes
    - **Where:** `app/Services/SmartLinks/SmartLinkRefresher.php:33`
    - **Affects:** Same observability concern as LIFE-11 but for the SmartLink refresh path. Manual "Refresh now" and the `smartlinks:refresh` cron can overlap. Category 2 / pattern: `lockForUpdate` (or atomic `increment()`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'consecutive_failures' => (int) $link->consecutive_failures + 1` with `$link->increment('consecutive_failures')` (separate call) or `DB::raw('consecutive_failures + 1')` inside `forceFill`.
    - **Technical:** Structurally identical to LIFE-11. `$link->consecutive_failures` is hydrated before the `SmartLinkResolver::resolve()` call. The failure path writes `value + 1` without a lock. The impact is the same: silent undercounting of persistent failures. The fix and the justification are identical to LIFE-11; these two findings share a root-cause pattern and should be bundled in the same fix session.
    - **Plain English:** Same tally-mark problem as LIFE-11 — two simultaneous "refresh" operations both read the same failure count, both add 1, and both write the same answer. The counter falls behind the true number of consecutive failures. Low impact at current scale; becomes increasingly inaccurate as user count grows.
    - **Evidence:**
        ```php
        // SmartLinkRefresher.php — non-atomic read-modify-write
        $link->forceFill([
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'unavailable',
            'consecutive_failures' => (int) $link->consecutive_failures + 1,  // stale read
        ])->save();
        ```
