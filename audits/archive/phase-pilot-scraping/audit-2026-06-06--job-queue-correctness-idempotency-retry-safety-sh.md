`★ Insight ─────────────────────────────────────`
The Partna job fleet is notably well-designed: every job has a `failed()` handler with `report()`, media processing uses Redis NX in-flight locks + terminal-state DB guards for idempotency, and notification jobs use `lockForUpdate` + a sent-at timestamp to prevent double-send. The only systemic gap is `$maxExceptions` on Cloudflare API jobs — distinct from `$tries` in Laravel, which counts total attempts including `release()` loops, `$maxExceptions` counts pure throws and short-circuits permanent 4xx failures faster.
`─────────────────────────────────────────────────`

# Job/Queue Correctness Audit — 2026-06-06

**Branch:** development
**Lens:** Job/Queue Correctness: idempotency, retry safety, ShouldBeUnique, missing $this->fail(), retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Jobs/Concerns/HasCloudflareRetryPolicy.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Console/Commands/RefreshIntegrationConnectionsCommand.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **JOB-1** · P2 — Cloudflare jobs retry on permanent 4xx without `$maxExceptions` short-circuit
    - **Where:** app/Jobs/Cloudflare/CloudflareCachePurgeJob.php; app/Jobs/Concerns/HasCloudflareRetryPolicy.php (consumed by `SyncSubdomainToKvJob` and `RetireSubdomainFromKvJob`)
    - **Affects:** Cloudflare edge-cache busting and KV routing-table syncs. A misconfigured or revoked API token causes 2–3 wasted retries and a ~80–100s delay before `failed()` fires and Nightwatch alerts. During that window the site payload stays stale at the edge (purge job) or the routing table stays out of sync (KV jobs).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $maxExceptions = 2;` directly to `CloudflareCachePurgeJob`.
        - Add `public int $maxExceptions = 2;` to `HasCloudflareRetryPolicy`; this propagates automatically to `SyncSubdomainToKvJob` and `RetireSubdomainFromKvJob`.
        - Optionally, inspect the wrapped HTTP status before re-throwing in each `handle()`: if it is a 4xx (permanent client error), call `$this->fail($e)` immediately to skip the retry machinery entirely.
    - **Technical:** Category 3 – retry on permanent errors. `CloudflareCachePurgeJob` declares `$tries = 3` with `$backoff = [5, 15, 60]` but no `$maxExceptions`. `SyncSubdomainToKvJob` and `RetireSubdomainFromKvJob` inherit `$tries = 3` and `$backoff = [10, 30, 60]` from `HasCloudflareRetryPolicy`, also without `$maxExceptions`. All three jobs call `->throw()` on the Cloudflare HTTP response, so a 401 (revoked token) or 403 (wrong permissions) will throw on every attempt. Without `$maxExceptions`, Horizon burns through all `$tries` before marking the job failed and invoking `failed()`. Adding `$maxExceptions = 2` causes Horizon to fail the job after the second consecutive throw, cutting the alert delay from ~80–100s to ~15–40s with no risk of masking genuine transient timeouts (which would succeed or release, not throw on every attempt). The `failed()` handlers in all three jobs already call `report($e)` correctly — this is purely a faster-failure fix, not a visibility fix.
    - **Plain English:** When the API key used to talk to Cloudflare is wrong or has been revoked, these three background tasks keep retrying two or three times — waiting seconds between each knock — before they finally give up and trigger an alert. Adding a simple trip-switch makes them fail after the second failed attempt rather than the third. This cuts the time before you see a Nightwatch alert roughly in half, with no downside: a real one-off hiccup (like a brief network blip) would still be retried once before the switch trips.
    - **Evidence:**
        ```php
        // CloudflareCachePurgeJob — no $maxExceptions
        public int $tries = 3;

        /** @var list<int> */
        public array $backoff = [5, 15, 60];

        public int $timeout = 15;
        ```
        ```php
        // HasCloudflareRetryPolicy (consumed by SyncSubdomainToKvJob + RetireSubdomainFromKvJob) — no $maxExceptions
        public int $tries = 3;

        public array $backoff = [10, 30, 60];
        ```
