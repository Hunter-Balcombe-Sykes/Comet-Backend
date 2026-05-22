- [ ] **CACHE-1** · P2 — 15 model observers registered create per-save dispatch hooks requiring audit for rebuild-on-write / fan-out antipatterns
    - **Where:** app/Providers/EventServiceProvider.php:37-51
    - **Affects:** Every create/update/delete on Professional, Site, Block, Service, ServiceCategory, Customer, BrandAffiliateInvite, BrandPartnerLink, CommissionMovement, CommissionPayout, ProfessionalIntegration, BrandProfile, BrandStoreSettings, SiteMedia, and AffiliateProductSelection — any synchronous rebuild dispatch or notification fan-out inside these observers amplifies single-user writes into multi-row recomputes.
    - **Effort:** L (~1–2d) — audit 15 observer classes, not a code change.
    - **What to do:**
        - Audit each non-commerce observer (`ProfessionalObserver`, `SiteObserver`, `BlockObserver`, `ServiceObserver`, `ServiceCategoryObserver`, `CustomerObserver`, `BrandAffiliateInviteObserver`, `BrandPartnerLinkObserver`, `ProfessionalIntegrationObserver`, `BrandProfileObserver`, `BrandStoreSettingsObserver`, `SiteMediaObserver`, `AffiliateProductSelectionObserver`) for dispatch of `Rebuild*Job`, `FanOut*Job`, or synchronous multi-row writes inside `created`/`updated`/`deleted` hooks.
        - For any observer that dispatches a rebuild job on every save, replace with push-invalidation + `CacheLockService::rememberLocked` (if the read is a dashboard) or a trigger-maintained signed-delta rollup (if the read is an aggregate table).
        - For any observer that dispatches a fan-out job creating N notification receipts per save, replace with lazy receipt creation (create receipt rows on first read, not at fan-out time) per the rebuild ADR.
    - **Technical:** The observer pattern is the most common home for rebuild-on-write in this codebase — the commerce rebuild already removed `commission_ledger_entries` observer-driven aggregates. Every remaining observer on a model with non-trivial write volume (Site, Block, SiteMedia are edited frequently; CommissionMovement rows arrive in webhook batches) is a candidate for the same antipattern. Without auditing the observer bodies themselves, the registration list is the dispatch surface that needs review.
    - **Plain English:** Think of observers like automatic notifications — every time someone saves a record, a hidden helper runs. If that helper decides to recalculate ALL the analytics for that entire hour from scratch, one small change becomes an expensive operation. We fixed this exact problem in the commerce system last month. Now we need to check whether the same pattern exists in the other 13 parts of the app that use these automatic helpers.
    - **Evidence:**
        ```php
        public function boot(): void
        {
            Professional::observe(ProfessionalObserver::class);
            Site::observe(SiteObserver::class);
            Block::observe(BlockObserver::class);
            Service::observe(ServiceObserver::class);
            ServiceCategory::observe(ServiceCategoryObserver::class);
            Customer::observe(CustomerObserver::class);
            BrandAffiliateInvite::observe(BrandAffiliateInviteObserver::class);
            BrandPartnerLink::observe(BrandPartnerLinkObserver::class);
            CommissionMovement::observe(CommissionMovementObserver::class);
            CommissionPayout::observe(CommissionPayoutObserver::class);
            ProfessionalIntegration::observe(ProfessionalIntegrationObserver::class);
            BrandProfile::observe(BrandProfileObserver::class);
            BrandStoreSettings::observe(BrandStoreSettingsObserver::class);
            SiteMedia::observe(SiteMediaObserver::class);
            AffiliateProductSelection::observe(AffiliateProductSelectionObserver::class);
        }
        ```
    - `[DRAFT, confidence: 0.6]` — Registrations are confirmed; observer bodies were not in the provided files, so the actual presence of rebuild/fan-out inside them is unverified.

- [ ] **CACHE-2** · P2 — `AccountTypeTransitionEvent` has 5 synchronous listeners that may contain cache-rebuild or fan-out work on a rare-but-complex event
    - **Where:** app/Providers/EventServiceProvider.php:11-18
    - **Affects:** Account type transitions (individual ↔ partner) — a low-frequency event, but if any listener does synchronous aggregate recomputation or multi-recipient notification dispatch, it blocks the transition request.
    - **Effort:** S (~0.5–1h) — audit 5 listener classes.
    - **What to do:**
        - Verify that `InvalidateProfessionalCacheOnTransition`, `SyncNotificationPreferencesOnTransition`, `ToggleStripeRequirementBannerOnTransition`, and `SetTransitionBannerOnTransition` are lightweight (single-row writes, enqueued mails, or cache key deletes — not multi-table rebuilds).
        - If any listener issues a rebuild job or a fan-out job synchronously, extract it to a queued job dispatch so the HTTP request completes quickly.
        - Confirm `LogAccountTypeTransition` is a fire-and-forget audit log append, not a synchronous analytics recompute.
    - **Technical:** Laravel's `$listen` array dispatches listeners synchronously by default (unless the listener implements `ShouldQueue`). The listener names suggest they do invalidation, notification preference sync, and banner toggling — likely lightweight — but if any one of them issues a query that touches aggregate tables or fans out notifications, the transition request blocks until that work finishes. Since transitions are rare this isn't a throughput risk, but it violates the principle that user-facing writes should never trigger synchronous aggregate rebuilds.
    - **Plain English:** When a user switches account types, five different helpers all run in sequence before the user gets a response. Four of them sound lightweight (clear a cache, sync some settings, show a banner). But if even one of them accidentally triggers a full recalculation, that user's request hangs. Given that account type switches are rare, this isn't urgent — but it's the same architectural smell we fixed in commerce, just on a less-busy road.
    - **Evidence:**
        ```php
        protected $listen = [
            AccountTypeTransitionEvent::class => [
                InvalidateProfessionalCacheOnTransition::class,
                LogAccountTypeTransition::class,
                // §28.5 side-effects — order matters: cache bust above ensures
                // AccountCapabilities::for() inside these listeners reads the new state.
                SyncNotificationPreferencesOnTransition::class,
                ToggleStripeRequirementBannerOnTransition::class,
                SetTransitionBannerOnTransition::class,
            ],
        ];
        ```
    - `[DRAFT, confidence: 0.5]` — Listener registrations confirmed; listener implementations were not in the provided files, so synchronous rebuild/fan-out inside them is unverified. Low-impact even if present because account type transitions are rare events.

- [ ] **CACHE-3** · P3 — `HandleAliasExpiringMail` passes a generic `object` to a queued mailable, bypassing Eloquent's `SerializesModels` contract clarity
    - **Where:** app/Mail/HandleAliasExpiringMail.php:9-12
    - **Affects:** Queue serialization of the handle-alias expiration mail — if `$alias` is ever not an Eloquent Model, serialization behaviour is unpredictable.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `public readonly object $alias` with the concrete model type (e.g. `public readonly HandleAlias $alias` or the actual Eloquent model class) so static analysis and the `SerializesModels` trait contract are explicit.
        - Verify that `$alias` is always an Eloquent Model instance at the dispatch site.
    - **Technical:** The `HandleAliasExpiringMail` implements `ShouldQueue`, meaning Laravel serializes it for the queue worker. The `SerializesModels` trait handles Eloquent Model properties by storing only the model identifier (class + key) and rehydrating on wakeup — far more efficient than full PHP serialization. Using `object` as the type hint doesn't break this (the trait checks `instanceof Model` at runtime), but it obscures the contract from static analysis and makes it possible for a future caller to pass a non-Model object that gets fully serialized inline, quietly bloating the queue payload.
    - **Plain English:** This email is queued for background delivery, which means the system needs to package it up and store it temporarily. The good news is that Eloquent models are stored super efficiently — just an ID reference. But the code labels this parameter as a generic "object" instead of naming the specific model type. It works today, but it's like labelling a box "stuff" — someone could put a couch in there later and the whole system would struggle.
    - **Evidence:**
        ```php
        class HandleAliasExpiringMail extends Mailable implements ShouldQueue
        {
            use Queueable, SerializesModels;

            public function __construct(
                public readonly object $alias,
                public readonly string $bucket  // 't3' or 't1'
            ) {}
        ```
    - `[DRAFT, confidence: 0.7]` — The `object` type hint is visible; whether `$alias` is always an Eloquent Model is unverified without the dispatch site (not in provided files).
