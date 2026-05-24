# Observability & Privacy Audit — 2026-05-24

**Branch:** development
**Lens:** missing structured context, PII in logs, silent catch blocks, gaps in exception/slow-job coverage, audit-log integrity
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Professional/AccountDeletionService.php
- app/Services/Auth/SupabaseAdminService.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/TwitchApiClient.php
- app/Services/Audit/StaffAuditService.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/SiteCacheService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#AUDIT-1** · P1 — Supabase Admin API response body (containing PII) written to logs on deletion failure
    - **Where:** app/Services/Professional/AccountDeletionService.php:383–387, app/Services/Auth/SupabaseAdminService.php:87–93
    - **Affects:** Any professional whose account purge triggers a Supabase auth-user deletion failure; GoTrue v2 error responses include the user object (email, `user_metadata`, phone). Also affects MFA unenrol failures — the exception message embeds the raw response body, which lands in Horizon's failed-jobs detail and Nightwatch exception traces.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `AccountDeletionService::deleteSupabaseAuthUser`, remove `'body' => $response->body()` from the `Log::error` context; keep `auth_user_id` and `status` for correlation.
        - In `SupabaseAdminService::unenrollMfaFactor`, stop embedding `$response->body()` in the `RuntimeException` message; throw with status code only (e.g. `"Supabase factor unenroll failed: HTTP {$response->status()}"`). If the body is needed for debugging, log it at `Log::debug` level separately.
    - **Technical:** The `createUser` path already applies a SHA-256 fingerprint pattern and includes an explicit comment explaining the GDPR retention problem (`// Privacy: never write raw email to logs`). The `deleteSupabaseAuthUser` and `unenrollMfaFactor` paths were written before that pattern was established and were not updated. GoTrue v2 returns the user object in error responses; logging `$response->body()` on a deletion failure writes the user's email (and any custom claims) into Nightwatch and Horizon's failed-jobs store — two retention windows that are outside the GDPR erasure sweep that wipes Postgres.
    - **Plain English:** When deleting a user's account fails partway through, the system writes the entire error reply from the auth service into the logs — including the user's email address. It's like shredding a document but first photocopying it and filing that copy in a cabinet the GDPR team can't reach. The fix is to log only the error code, not the full reply, exactly as the account-creation path already does.
    - **Evidence:**
        ```php
        // AccountDeletionService.php:383-387
        Log::error('Supabase auth user deletion failed', [
            'auth_user_id' => $authUserId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```
        ```php
        // SupabaseAdminService.php:87-93
        throw new RuntimeException(
            "Supabase factor unenroll failed: HTTP {$response->status()} body={$response->body()}"
        );
        ```

- [ ] **#AUDIT-2** · P1 — Customer and professional email addresses serialised into Redis job payloads
    - **Where:** app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:30–34, app/Jobs/Notifications/SendEnquiryNotificationJob.php:37–40
    - **Affects:** GDPR/Privacy Act right-of-erasure compliance. A deletion request wipes Postgres but cannot reach serialised job payloads in Redis. Jobs with `$tries=3` and backoff up to 180s can sit in Redis for several minutes; exhausted jobs land in the failed-jobs table with no expiry at all. Both email addresses (professional notification inbox, customer email) become orphaned PII.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `public readonly string $notificationEmail` from `SendEnquiryNotificationJob`'s constructor. Resolve the notification email inside `handle()` from the `Enquiry` row (which already fetches the enquiry by `$enquiryId`) or from the professional's contact settings.
        - Remove `public readonly string $email` from `SyncCustomerMarketingOptInJob`'s constructor. Inside `handle()`, look up the Customer directly by `professional_id` + `email` (the `email` passed in is used as a lookup key anyway — pass the customer's UUID instead and look it up inside `handle()`).
    - **Technical:** Laravel serialises all public constructor properties into the job payload written to Redis. `SendEnquiryNotificationJob` already demonstrates awareness of this risk — its `failed()` handler explicitly omits `notificationEmail` from log context with a comment explaining GDPR retention. But the constructor still embeds it in the Redis payload, so the fix is one layer higher: stop passing the raw email in the constructor entirely and resolve it from the database at `handle()` time. `SyncCustomerMarketingOptInJob` has no such guard and also logs the email in `failed()` (see AUDIT-3).
    - **Plain English:** When the system queues a background task, it writes a small instruction note into a temporary storage area (Redis). Right now those notes include the email addresses of customers and professionals. If someone asks to be forgotten and we delete their record from the main database, the note in Redis still has their email. The fix is to store only a reference number on the note and look up the email from the database when the task actually runs — by which point it will have been deleted if erasure was requested.
    - **Evidence:**
        ```php
        // SendEnquiryNotificationJob.php:37-40
        public function __construct(
            public readonly string $enquiryId,
            public readonly string $notificationEmail,
        ) {
        ```
        ```php
        // SyncCustomerMarketingOptInJob.php:30-34
        public function __construct(
            public readonly string $professionalId,
            public readonly string $email,
            public readonly bool $subscribed,
        ) {
        ```

- [ ] **#AUDIT-3** · P1 — Customer email logged in failed-job handler
    - **Where:** app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:56–62
    - **Affects:** Every customer whose marketing-opt-in sync job exhausts its three retries — their email address is written into the application log and persists in Nightwatch under the log-aggregator's retention window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'email' => $this->email` from the `Log::error` context array in `SyncCustomerMarketingOptInJob::failed()`.
        - Keep `'professional_id'` for correlation — support can recover the customer email from the database using the professional ID and the subscription record if needed.
    - **Technical:** `SendEnquiryNotificationJob::failed()` already demonstrates the correct pattern — it explicitly omits the notification email from its log context with a comment (`// Don't log the professional's notification_email — log retention exceeds GDPR/Privacy Act expectations`). `SyncCustomerMarketingOptInJob::failed()` logs the raw customer email, directly contradicting that established convention. Since `$this->email` is a constructor property (covered by AUDIT-2's fix), removing it from the `failed()` log is the immediate low-risk fix regardless of whether the constructor refactor lands first.
    - **Plain English:** When a background task fails after three attempts, the system records a log entry. For this particular task, that log entry includes the customer's email address, which then lives in Nightwatch for months. The sibling task (sending enquiry notifications) already avoids this. This one just missed the same rule.
    - **Evidence:**
        ```php
        // SyncCustomerMarketingOptInJob.php:56-62
        Log::error('notifications.sync_customer_marketing_opt_in.failed', [
            'professional_id' => $this->professionalId,
            'email' => $this->email,
            'subscribed' => $this->subscribed,
            'error' => $e->getMessage(),
        ]);
        ```

---

## P2 — Should fix

- [ ] **#AUDIT-4** · P2 — Third-party streaming API response bodies logged verbatim on error
    - **Where:** app/Services/Streaming/KickApiClient.php:63–67, app/Services/Streaming/TwitchApiClient.php:55–59
    - **Affects:** Log aggregator / Nightwatch retention. Current Kick and Kick Helix error shapes are documented and carry no PII (slugs and stream metadata only). However API contracts drift, and a future error shape could echo back request headers or authentication context. Logging full bodies at `error` level stores them indefinitely in Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop `'body' => $response->body()` from both `Log::error` calls; keep `'status'` for alerting.
        - If response bodies are needed for troubleshooting, emit them at `Log::debug` level so they are excluded from production log aggregation.
    - **Technical:** Both clients call public REST APIs for live-status data; neither currently returns PII. The concern is forward-looking: the `SupabaseAdminService::createUser` pattern (fingerprinting sensitive fields rather than logging raw data) reflects the codebase standard that external API responses should not be logged verbatim. Applying the same discipline here costs nothing and removes a latent risk before the streaming feature sees real traffic.
    - **Plain English:** When Twitch or Kick's API returns an error, the system saves the entire reply into the logs permanently. Today those replies contain only usernames and streaming state. If either service changes their error format next year and starts including more data, it could end up stored in the logs. Logging only the error code is the safe default, and the full reply can still be enabled temporarily for debugging.
    - **Evidence:**
        ```php
        // KickApiClient.php:63-67
        Log::error('streaming.api_error', [
            'platform' => 'kick',
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```
        ```php
        // TwitchApiClient.php:55-59
        Log::error('streaming.api_error', [
            'platform' => 'twitch',
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```

- [ ] **#AUDIT-5** · P2 — Staff audit write-failure warning lacks request correlation ID
    - **Where:** app/Services/Audit/StaffAuditService.php:41–46
    - **Affects:** Ops debugging — when a staff audit row fails to insert, the `staff.audit.write_failed` warning cannot be linked to the originating HTTP request in Nightwatch, making post-incident forensics slower.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'request_id' => request()?->header('X-Request-Id')` to the `Log::warning` context in `StaffAuditService::record()`.
        - Optionally add `'professional_id' => $professional?->id` — the caller already has it and it's the primary forensic anchor for staff actions.
    - **Technical:** `FeatureFlagService` and `NotificationPublisher` both include `request()?->header('X-Request-Id')` in their warning-level log contexts. `StaffAuditService` is invoked from `RecordStaffAuditEntry` middleware which has the full `Request` object available, but the service itself receives no `Request` argument — `request()` (the global helper) is the correct lightweight solution here, consistent with the existing convention. Without the correlation ID, a Nightwatch alert for `staff.audit.write_failed` shows only the route and HTTP method, making it impossible to trace to a specific request without guessing from timestamps.
    - **Plain English:** When the system fails to record a staff action in the audit trail, it writes a warning to the logs. But that warning is missing the request tracking number, so if you try to investigate later you can't match the warning to the specific action that caused it. Other parts of the system already include this tracking number in similar warnings — this one just needs to be updated to match.
    - **Evidence:**
        ```php
        // StaffAuditService.php:41-46
        Log::warning('staff.audit.write_failed', [
            'exception' => $e->getMessage(),
            'route' => $route,
            'http_method' => $httpMethod,
        ]);
        ```

---

## P3 — Nice to have

- [ ] **#AUDIT-6** · P3 — Silent lock-release failures produce no signal in monitoring
    - **Where:** app/Services/Cache/CacheLockService.php (multiple `finally` blocks), app/Services/Cache/SiteCacheService.php:194–200
    - **Affects:** Ops — a persistent Redis connectivity issue that prevents every lock release will go undetected until another symptom surfaces. No counter, no sampled log, no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In each silent `catch (Throwable)` block in `CacheLockService` and `SiteCacheService::releaseLockQuiet`, consider incrementing a Redis counter (e.g. `INCR cache:lock_release_failures`) or emitting a 1%-sampled `Log::debug` with a distinct key. A counter lets an `AggregateCacheMetricsJob`-style reader surface trends without log noise.
        - The silent suppression must be preserved — a `finally` block must never throw and mask the original exception. Only add observability, not control flow.
    - **Technical:** The suppression pattern is architecturally correct: `Cache::lock()->release()` throws if the driver doesn't support release-after-expiry or if the lock has already expired, and surfacing that in a `finally` block would mask the actual closure exception. The gap is purely observability — a Redis connectivity issue that causes every lock release across every request to fail would produce zero signal until a downstream symptom (lock contention, stale cache) surfaces, by which point diagnosis is hard.
    - **Plain English:** When the system finishes using a lock it politely releases it, and if the release fails it shrugs and moves on — which is the right behaviour. But it shrugs completely silently. If the temporary storage system is broken for an hour, every single lock release fails and nobody knows until something else starts going wrong. Adding a simple failure counter means the monitoring system can notice if lock releases start consistently failing, without changing how errors are handled.
    - **Evidence:**
        ```php
        // CacheLockService.php — representative finally block (pattern repeats)
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // ignore — lock may have auto-expired
            }
        }
        ```
        ```php
        // SiteCacheService.php:194-200
        private function releaseLockQuiet(\Illuminate\Contracts\Cache\Lock $lock): void
        {
            try {
                $lock->release();
            } catch (Throwable) {
                // ignore — lock may have auto-expired or been released elsewhere
            }
        }
        ```
