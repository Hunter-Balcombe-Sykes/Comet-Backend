`★ Insight ─────────────────────────────────────`
`SyncCustomerMarketingOptInJob` has no `failed()` method at all — in Laravel, an unimplemented `failed()` on a job that exhausts its `$tries` produces zero logs, zero Nightwatch signals, and no failed-jobs counter increment. The framework silently marks it failed in Redis and moves on.
`─────────────────────────────────────────────────`

# Job/Queue Correctness Audit — 2026-05-19

**Branch:** development
**Lens:** Job/Queue Correctness: idempotency, retry safety, ShouldBeUnique, missing $this->fail(), retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
- app/Jobs/Stripe/ExecuteCommissionPayoutJob.php
- app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php
- app/Jobs/Stripe/VoidableCommissionsAndWarningsJob.php
- app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php
- app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php
- app/Jobs/Shopify/CreateShopifyMetafieldsJob.php
- app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/NudgeStuckOnboardingJob.php
- app/Jobs/Notifications/InviteExpirySweepJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/ProvisionBrandDnsJob.php
- app/Jobs/Concerns/HasCloudflareRetryPolicy.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#JOB-1** · P2 — Five jobs silently fail: `report($e)` missing in `failed()`
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:122, app/Jobs/Notifications/SendBrandStatusNotificationJob.php:77, app/Jobs/Notifications/NudgeStuckOnboardingJob.php:137, app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:117, app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php:194
    - **Affects:** All five jobs exhaust their `$tries` without forwarding the exception to Nightwatch. Failures increment the failed-jobs Redis counter (Horizon UI shows them as failed) but produce no Nightwatch alert and no exception trace — an ops team only discovers the failure by actively checking Horizon or Laravel Cloud logs. For `SendTransactionalNotificationEmailJob` this means a dropped payout or commission email is completely invisible unless someone goes looking.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` as the first line of each `failed()` method in the five listed jobs, following the pattern already established by `ExecuteCommissionPayoutJob`, `ProcessShopifyOrderWebhookJob`, `VoidPendingCommissionsForLinkJob`, and all other financial and critical path jobs in the codebase.
        - For `CreateShopifyAffiliateDiscountJob.failed()`, also add a `Log::error()` call alongside `report($e)` — the method currently only updates metadata with no log output of any kind.
    - **Technical:** The codebase uses `report($e)` in `failed()` as the mechanism for forwarding exceptions to Nightwatch (see CLAUDE.md: "Nightwatch Alert Model — Alerts trigger on issues (exceptions …)"). Without `report($e)`, Nightwatch never sees the exception; it never fires an alert; failed-job dashboards in Horizon show the count but only structural triage tools catch it, not on-call alerting. All five affected jobs share the identical root cause: the author wrote `Log::error()` (correct for breadcrumb logging) but omitted `report($e)` (required for the Nightwatch signal). The established pattern in every correctly-instrumented job is both calls together.
    - **Plain English:** Think of `report($e)` like forwarding a complaint to your incident pager, while `Log::error()` is writing it in a notebook. These five jobs write the complaint in the notebook but never page anyone. For notification jobs that's annoying. For the job that sends payout and commission emails (`SendTransactionalNotificationEmailJob`), it means a professional might silently stop receiving emails about their earnings and nobody gets woken up to fix it.
    - **Evidence:**
        ```php
        // FanOutBrandStatusNotificationJob.php:122
        public function failed(\Throwable $e): void
        {
            Log::error('FanOutBrandStatusNotificationJob failed', [
                'brand_professional_id' => $this->brandProfessionalId,
                'brand_status' => $this->brandStatus,
                'message' => $e->getMessage(),
            ]);
        }

        // SendTransactionalNotificationEmailJob.php:117
        public function failed(\Throwable $e): void
        {
            Log::error('Transactional notification email failed', [
                'notification_id' => $this->notificationId,
                'category' => $this->category,
                'professional_id' => $this->professionalId,
                'message' => $e->getMessage(),
            ]);
        }

        // CreateShopifyAffiliateDiscountJob.php:194 — no log at all, only metadata update
        public function failed(\Throwable $e): void
        {
            $integration = ProfessionalIntegration::find($this->integrationId);
            $integration?->mergeProviderMetadata(['partna_discount_state' => 'failed']);
        }
        ```

- [ ] **#JOB-2** · P2 — `SyncSubdomainToKvJob` missing `ShouldBeUnique` — stale KV write possible under rapid re-dispatch
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:23
    - **Affects:** Edge routing for `*.partna.au` is driven by Cloudflare KV. If two concurrent job instances race, the one that reads a stale DB snapshot last wins the KV write — visitor traffic for a handle could be sent to the wrong destination (e.g., a stale `{type:"affiliate", redirect:...}` after the professional disconnects from a brand) until the next dispatch overwrites it. The window is narrow but the consequence is misdirected traffic with no error visible to ops.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Implement `ShouldBeUnique` on `SyncSubdomainToKvJob` with `uniqueId()` returning the `$professionalId`.
        - Set `$uniqueFor` to slightly longer than `$timeout` (e.g., `45`) so the lock releases cleanly if the job is killed mid-flight.
        - Note: `RetireSubdomainFromKvJob` is a separate writer for hard-deletion paths and is unaffected by this change. The architecture test `SubdomainKvWritersTest` referenced in the plan remains the guard against other callers.
    - **Technical:** `SyncSubdomainToKvJob` is dispatched by observers on handle change, brand-partner-link change, and brand URL change — scenarios that commonly produce rapid back-to-back dispatches. The job reads Professional and BrandPartnerLink from DB in `handle()`, then writes KV. Without `ShouldBeUnique`, two workers can both reach the `BrandPartnerLink::query()->value('site_url')` line at different committed DB states (e.g., job A reads after disconnect, job B reads after reconnect to a new brand, job B writes the correct affiliate entry, then job A writes the now-stale delete path). With `ShouldBeUnique(uniqueId: $professionalId)`, the second dispatch waits until the first completes, then runs once against the latest DB state — exactly the desired behaviour. This pattern is already used by `CreateShopifyMetafieldsJob` and `CreateShopifyAffiliateDiscountJob` for the same reason.
    - **Plain English:** The Cloudflare routing table is a lookup that tells the edge "send this visitor to Hydrogen, or redirect them, or serve the Astro app." This job keeps it up to date. Without a uniqueness lock, two copies of the job can run at the same time, reading different snapshots of the database, and the older one can win the race and overwrite the newer correct answer. The result is visitors sent to the wrong place for a short window — not a crash, but a routing mistake that's silent and hard to spot.
    - **Evidence:**
        ```php
        // app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:23
        class SyncSubdomainToKvJob implements ShouldQueue
        {
            use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;
        
            public int $timeout = 30;
        
            public function __construct(public readonly string $professionalId)
            {
                $this->onQueue('integrations');
            }
        ```

## P3 — Nice to have

- [ ] **#JOB-3** · P3 — `SyncCustomerMarketingOptInJob` has no `failed()` method — completely silent on exhaustion
    - **Where:** app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:17
    - **Affects:** When this job exhausts its 3 tries, Horizon's failed-jobs counter increments but there is no log entry, no Nightwatch signal, and no breadcrumb. The failure is only visible by manually inspecting the failed-jobs list in Horizon. Functionally, the consequence is low: the job comment documents that `isMarketingOptedIn()` falls back to a live DB lookup when the cached column is null, so the absence of the cache update doesn't corrupt state. The gap is purely observability.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a minimal `failed()` method following the hygiene pattern used by sibling notification jobs: `report($e)` + `Log::error()` with `professional_id` and `email` context (note: `email` is PII — use the `email` field only in logs that follow the project's log-retention policy; alternatively log only `professional_id` + error message as `SendEnquiryNotificationJob` does for similar PII-adjacent context).
    - **Technical:** Laravel's queue worker calls `failed()` after all retry attempts are exhausted. Without the method, the job silently transitions to the failed state with only Redis metadata recording the failure. Adding `failed()` costs two lines and closes the observability gap uniformly across all jobs in the `app/Jobs/` namespace.
    - **Plain English:** This job updates a marketing preferences flag on a customer record. When it fails three times in a row, the system currently just quietly gives up. Adding a `failed()` method is like setting up a "call me if this breaks" note — it costs nothing and means you'd actually find out if something goes wrong.
    - **Evidence:**
        ```php
        // app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php — entire class, no failed() method
        class SyncCustomerMarketingOptInJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
        
            public int $tries = 3;
            public int $maxExceptions = 2;
            public int $backoff = 30;
            public int $timeout = 30;
        
            public function __construct(
                public readonly string $professionalId,
                public readonly string $email,
                public readonly bool $subscribed,
            ) {
                $this->onQueue('notifications');
            }
        
            public function handle(): void
            {
                // ... no failed() method follows
            }
        }
        ```
