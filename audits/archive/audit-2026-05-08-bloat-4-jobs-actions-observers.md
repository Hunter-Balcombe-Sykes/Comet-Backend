`★ Insight ─────────────────────────────────────`
- The `CreateShopifySalesChannelJob` finding is compounded by a second bug introduced in commit `0fab068` (rebrand sidest → partna): both name-matching helpers in `findPublicationId` and `findExistingPublicationId` still search for `'side st'`/`'sidest'` strings, meaning even a manually-created publication named "Partna" would be silently missed — two independent defects converging on the same fallback path.
- The stale KV entry pattern (KV-1) is a DeepSeek miss that was only catchable by cross-reading `ProfessionalObserver` against `SiteObserver` — the latter demonstrates the correct retirement pattern (`_oldSubdomainPendingRetire` stash in `updating()`) that `ProfessionalObserver` lacks.
- INTEG-2 (confidence 0.7, Fresha delta sync no visible dispatch) is dropped: it fully overlaps with INTEG-1, Fresha sync is explicitly marked "unverified" in project memory, and the Fresha integration status note ("service sync scaffolded but unverified") makes it expected dead code rather than a regression.
`─────────────────────────────────────────────────`

# Dead Background-Task Code Audit — 2026-05-08

**Branch:** development
**Lens:** Unused jobs, actions, observers, and dead background-task code
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cloudflare/ProvisionBrandDnsJob.php
- app/Jobs/Cloudflare/RetireBrandDnsJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Jobs/Fresha/PushServiceToFreshaJob.php
- app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php
- app/Jobs/Gdpr/ExportProfessionalDataJob.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/InviteExpirySweepJob.php
- app/Jobs/Notifications/NudgeStuckOnboardingJob.php
- app/Jobs/Notifications/SendBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php
- app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php
- app/Jobs/Shopify/CreateShopifyCollectionsJob.php
- app/Jobs/Shopify/CreateShopifyMetafieldsJob.php
- app/Jobs/Shopify/CreateShopifySalesChannelJob.php
- app/Jobs/Shopify/CreateStorefrontAccessTokenJob.php
- app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Jobs/Shopify/Gdpr/RedactShopJob.php
- app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php
- app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php
- app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php
- app/Jobs/Shopify/RegisterShopifyWebhooksJob.php
- app/Jobs/Shopify/SetShopifySetupCompleteJob.php
- app/Jobs/Shopify/SyncShopifyBrandDesignJob.php
- app/Jobs/Square/PushServiceToSquareJob.php
- app/Jobs/Square/SyncSquareCatalogDeltaJob.php
- app/Jobs/Store/SeedAffiliateDefaultSelectionsJob.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Jobs/Stripe/ExecuteCommissionPayoutJob.php
- app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
- app/Jobs/Stripe/VoidExpiredPayoutsJob.php
- app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php
- app/Actions/Site/UpdateSiteAction.php
- app/Actions/Subscription/CancelProfessionalSubscriptionAction.php
- app/Actions/Subscription/ChangeProfessionalPlanAction.php
- app/Actions/Subscription/CreateProfessionalSubscriptionAction.php
- app/Actions/Subscription/ResumeProfessionalSubscriptionAction.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/BrandAffiliateInviteObserver.php
- app/Observers/Core/BrandPartnerLinkObserver.php
- app/Observers/Core/BrandProfileObserver.php
- app/Observers/Core/CommissionMovementObserver.php
- app/Observers/Core/CommissionPayoutObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/ProfessionalIntegrationObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Observers/Retail/BrandStoreSettingsObserver.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete ✅
- P3 Low: 3 of 3 complete ✅

---

## P2 — Should fix

- [x] **#CHAIN-1** · P2 — `CreateShopifySalesChannelJob` never dispatched; publication-name matching stale post-rebrand
    - **Where:** app/Jobs/Shopify/CreateShopifyCollectionsJob.php (`findPublicationId`); app/Jobs/Shopify/CreateShopifySalesChannelJob.php (`findExistingPublicationId`); app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php (chain docblock)
    - **Affects:** Every brand connecting Shopify — the app-owned sales channel publication is never created, so `findPublicationId` always falls back to Online Store and the intended per-channel collection publishing never fires. A second independent defect, introduced by commit `0fab068` (rebrand sidest → partna), means both name-matching helpers still search for `'side st'`/`'sidest'` and would silently miss a `'partna'`-named publication even if one were manually created.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wire `CreateShopifySalesChannelJob` into the install chain: dispatch it from `CreateShopifyMetafieldsJob::handle()` in place of the current `CreateShopifyCollectionsJob::dispatch(...)` call, and move the `CreateShopifyCollectionsJob` dispatch to the end of `CreateShopifySalesChannelJob::handle()` so the channel publication exists before collections attempt to publish to it.
        - Update `CreateShopifyCollectionsJob::findPublicationId` to include `str_contains($name, 'partna')` alongside the existing checks (keep the old strings for backward compat with legacy integrations that may have a pre-rebrand publication).
        - Update `CreateShopifySalesChannelJob::findExistingPublicationId` the same way.
    - **Technical:** The install chain is strictly sequential by job chaining: `CreateShopifyMetafieldsJob` → `CreateShopifyCollectionsJob` → `CreateShopifyAffiliateDiscountJob` → `BackfillBrandHasEnabledVariantsJob`. `CreateShopifySalesChannelJob` has full idempotency logic (`findExistingPublicationId`, `ShouldBeUnique`, `mergeProviderMetadata`) but has no dispatch site anywhere in this chain — it is dead code. `CreateShopifyCollectionsJob::findPublicationId` was written assuming the channel job ran first; since it never does, the fallback to Online Store is taken on every brand connection. Commit `0fab068` compounded this: both `findPublicationId` and `findExistingPublicationId` match on `'side st'`/`'sidest'` strings but the Shopify app listing name is now "Partna", so even a publication created by the channel job (once wired) would not be found by the stale string check.
    - **Plain English:** The Shopify setup process is like an assembly line with a checklist of steps. One step — "create our own product shelf in Shopify" — has the machinery built and ready, but nobody ever plugged it into the line. Every brand silently ends up on Shopify's generic "Online Store" shelf instead of Partna's dedicated shelf. On top of that, the worker that searches for the shelf is looking for a label that says "sidest" — but the company renamed it to "Partna" months ago. Even if the machine were plugged in tomorrow, the worker would walk straight past it. Fix: add the machine to the line, and update the label it looks for.
    - **Evidence:**
        ```php
        // BackfillBrandHasEnabledVariantsJob.php — documented chain never includes CreateShopifySalesChannelJob:
        // Dispatch order:
        //   ShopifyIntegrationController → CreateShopifyMetafieldsJob →
        //     CreateShopifyCollectionsJob →
        //       CreateShopifyAffiliateDiscountJob →
        //         BackfillBrandHasEnabledVariantsJob (this, last in the chain).
        ```
        ```php
        // CreateShopifyCollectionsJob.php — findPublicationId() stale name check:
        if (str_contains($name, 'side st') || str_contains($name, 'sidest')) {
            return $id;
        }
        if ($name === 'online store') {
            $onlineStoreId = $id;
        }
        // ...
        if (! empty($edges)) {
            Log::warning('No suitable publication found for collection publishing', [
                'integration_id' => $this->integrationId,
                'available_publications' => collect($edges)->pluck('node.name')->toArray(),
            ]);
        }
        ```
        ```php
        // CreateShopifySalesChannelJob.php — findExistingPublicationId() same stale check:
        foreach ($edges as $edge) {
            $name = strtolower(trim((string) Arr::get($edge, 'node.name', '')));
            if (str_contains($name, 'side st') || str_contains($name, 'sidest')) {
                return (string) Arr::get($edge, 'node.id', '');
            }
        }
        ```

---

## P3 — Nice to have

- [x] **#INTEG-1** · P3 — Booking sync push jobs redundantly re-check the feature flag already gated by `ServiceObserver`
    - **Where:** app/Observers/Core/ServiceObserver.php (`shouldDispatchFreshaSync`, `shouldDispatchSquareSync`); app/Jobs/Fresha/PushServiceToFreshaJob.php:35–37; app/Jobs/Square/PushServiceToSquareJob.php (handle guard)
    - **Affects:** Queue throughput when Fresha/Square sync features are disabled — jobs are dispatched to Redis and dequeued by workers only to exit immediately. More importantly, if the feature is enabled at dispatch time but disabled before the job runs (e.g., a config-only deploy mid-flight), the job silently discards the service mutation with no log entry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `config('partna.features.fresha_sync')` early-return from `PushServiceToFreshaJob::handle()` and the equivalent from `PushServiceToSquareJob::handle()`.
        - Keep the feature-flag gates in `ServiceObserver::shouldDispatchFreshaSync()` and `shouldDispatchSquareSync()` — these remain the canonical dispatch guards.
    - **Technical:** `ServiceObserver::shouldDispatchFreshaSync` checks `config('partna.features.fresha_sync', false)` before dispatching. `PushServiceToFreshaJob::handle()` then checks the same config and returns early if disabled. Under normal operation the job-level guard never fires; the observer already blocked dispatch. The problem is the silent-discard path: if the feature flag is toggled between `dispatch()` and `handle()`, the mutation is dropped with no warning log, making the data inconsistency invisible. The `ServiceObserver` guard, by contrast, produces observable dispatch-time behavior. The same pattern exists in `PushServiceToSquareJob`.
    - **Plain English:** There's a bouncer at the club door checking IDs before letting anyone in, and a second bouncer standing inside an empty room checking the same IDs again. The door bouncer already stopped everyone who shouldn't enter — the inside bouncer has never once turned anyone away. Worse, if the club rules change between the door and the room, someone could be let in and then quietly shown out the back with no record. One bouncer at the door is the right answer.
    - **Evidence:**
        ```php
        // ServiceObserver.php — already gates dispatch on the feature flag:
        private function shouldDispatchFreshaSync(?Professional $professional): bool
        {
            if (! (bool) config('partna.features.fresha_sync', false)) {
                return false;
            }
            // ...
        }
        ```
        ```php
        // PushServiceToFreshaJob.php — same flag re-checked in handle():
        public function handle(FreshaServiceSyncService $syncService): void
        {
            if (! (bool) config('partna.features.fresha_sync', false)) {
                return;
            }
            // ...
        }
        ```

- [x] **#KV-1** · P3 — Old Cloudflare KV handle entry not retired when a professional's handle changes
    - **Where:** app/Observers/Professional/ProfessionalObserver.php (`updated`); app/Jobs/Cloudflare/SyncSubdomainToKvJob.php (`handle`)
    - **Affects:** Any brand or affiliate who renames their handle — the old `<handle>.partna.au` subdomain continues to resolve via a stale KV routing entry. If the old handle is later claimed by a different user, the Worker routes their subdomain to the wrong routing profile until the KV entry is overwritten.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `ProfessionalObserver::updated()`, before dispatching the new KV write, read the old handle via `$professional->getOriginal('handle')` and dispatch a deletion job (`RetireBrandDnsJob` already demonstrates the pattern for DNS — create a corresponding `RetireSubdomainFromKvJob` or pass `$oldHandle` to `SyncSubdomainToKvJob` and delete it at the top of `handle()`).
        - Mirror the `SiteObserver::updating()` stash pattern (`$site->_oldSubdomainPendingRetire`) if a pre-save hook is needed to capture the old value cleanly, though `getOriginal()` in `updated()` is sufficient here.
    - **Technical:** `ProfessionalObserver::updated()` dispatches `SyncSubdomainToKvJob::dispatch($professional->id)` when `handle` changes. The job writes `$kv->put($pro->handle, ...)` using the *current* handle as the KV key — the old handle key is never touched. `SiteObserver` demonstrates the correct retirement pattern: `updating()` stashes `$site->_oldSubdomainPendingRetire = $site->getOriginal('subdomain')`, and `saved()` dispatches `RetireBrandDnsJob` for that old value. `ProfessionalObserver` has no equivalent. Since `getOriginal('handle')` is accessible inside the `updated()` hook after save, no pre-save stash is strictly necessary — the old value can be passed directly to a deletion dispatch.
    - **Plain English:** When someone changes their username, the app correctly writes the new address into Cloudflare's routing directory. But the old address entry stays in the directory forever. Anyone navigating to `old-handle.partna.au` still gets routed somewhere. The fix is simple: cross out the old entry at the same moment the new one is written. The code already does exactly this for brand domain records when a brand renames their site — the same pattern just needs to be applied here for handle changes.
    - **Evidence:**
        ```php
        // ProfessionalObserver.php — dispatches KV write for new handle, no cleanup of old:
        if ($professional->wasChanged('handle')) {
            try {
                SyncSubdomainToKvJob::dispatch((string) $professional->id);
        ```
        ```php
        // SyncSubdomainToKvJob.php — writes current handle as KV key; old key never deleted:
        if ($pro->isBrand()) {
            $kv->put($pro->handle, ['type' => 'brand']);

            return;
        }
        // ...
        $kv->put($pro->handle, ['type' => 'affiliate', 'redirect' => $siteUrl]);
        ```
        ```php
        // SiteObserver.php — correct retirement pattern that ProfessionalObserver should mirror:
        public function updating(Site $site): void
        {
            if ($site->isDirty('subdomain')) {
                // Stash on the model so saved() can dispatch retirement of the old CNAME.
                $site->_oldSubdomainPendingRetire = $site->getOriginal('subdomain');
            }
        }
        ```

- [x] **#SCHED-1** · P3 — Six scheduler-driven jobs have no documented dispatch site or confirmed scheduler registration
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php; app/Jobs/Notifications/NudgeStuckOnboardingJob.php; app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php; app/Jobs/Streaming/CheckStreamingLiveStatusJob.php; app/Jobs/Stripe/VoidExpiredPayoutsJob.php; app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
    - **Affects:** Invite expiry, onboarding nudges, weekly analytics emails, live-stream status polling, payout voiding, and daily commission processing. If any scheduler entry is accidentally dropped, the feature silently stops with no test failures or error logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm each job is registered in `routes/console.php` (Laravel 12 replaces `App\Console\Kernel::schedule()` — per CLAUDE.md, `app/Console/Kernel.php` no longer exists).
        - Add a one-line docblock to each job's class comment documenting its schedule: e.g., `// Scheduled: daily at 03:00 UTC in routes/console.php`.
        - Consider a lightweight test (or CI grep) that asserts each class appears somewhere in `routes/console.php`, so a dropped entry fails the build rather than silently skipping production sweeps.
    - **Technical:** All six jobs have zero-argument constructors, sweep-style logic, and `tries`/`timeout` values configured for long-running scans — unmistakably scheduler-driven. None of them appear in any observer, controller, or job-chain dispatch within the provided files. In Laravel 12, `routes/console.php` is the canonical home for `$schedule->job(ClassName::class)` entries; `App\Console\Kernel` no longer exists. Without seeing `routes/console.php`, registration cannot be confirmed from the provided files alone. `VoidExpiredPayoutsJob` makes the risk concrete: it documents a past production regression caused by exactly this kind of missing enforcement — "`void_at` was being written but never enforced … Closes #CR-003."
    - **Plain English:** These six jobs are automatic timers — they're supposed to fire every morning, every week, every two minutes. Their actual logic is well-written. What we can't confirm from the files here is whether anyone actually set the alarm. One of them even has a note saying "without this alarm, a feature we promised users was silently broken for a period." Every job should carry a single line in its comment saying exactly when and how often it runs, so any developer can verify the clock is still ticking.
    - **Evidence:**
        ```php
        // InviteExpirySweepJob.php — zero-arg constructor, clearly scheduler-driven, no observer wires it:
        public function __construct()
        {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // VoidExpiredPayoutsJob.php — documents a previous silent production failure from missing dispatch:
        // Backstop for the UI promise on the affiliate dashboard ("payout will be
        // voided in N days if you don't connect Stripe"). Without this job the
        // per-payout void_at column was being written but never enforced — the
        // older 30-day ledger-entry void path is unrelated and operates one layer
        // below. Closes #CR-003.
        ```
