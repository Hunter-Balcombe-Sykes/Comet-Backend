`★ Insight ─────────────────────────────────────`
Key findings from verification:
- `notification_categories` has exactly **3 occurrences**: declared in `AccountCapabilitySet`, constructed in `AccountCapabilities`, and nowhere else — confirming no consumer exists
- `Site::theme()` is definitively **not defined** in the model (confirmed by direct Read), yet `StaffUserController` references it in 5 places including `->with(['site.theme'])` eager load, which Laravel would throw a `BadMethodCallException` on
- `ServiceCategory` → `ServicePolicy` registration exists at line 111 of `AppServiceProvider`; all moderation model policies are registered — no coverage gaps
- DeepSeek's proposed CAP-1 fix is incorrect as written: adding a `notification_categories` check to `SendTransactionalNotificationEmailJob` would block currently-valid categories (`analytics_weekly`, `policy_update`, etc.) that are intentionally absent from `CAPABILITY_GATE_MAP` for the standalone model
`─────────────────────────────────────────────────`

---

# AccountCapabilities Audit — 2026-05-31

**Branch:** development
**Lens:** AccountCapabilities bypass, missing capability checks before notification/API/job actions, AccountType enum integrity, moderation/feedback actions gated by capabilities
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Notifications/SendSubscriptionConfirmationJob.php
- app/Jobs/Notifications/SendFeedbackEmailJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Moderation/NotifyReportedUserJob.php
- app/Jobs/Moderation/NotifyReporterJob.php
- app/Jobs/Moderation/NotifyOnCallStaffJob.php
- app/Services/Moderation/ContentReportService.php
- app/Services/Moderation/ModerationActionDispatcher.php
- app/Services/Moderation/ModerationDecisionService.php
- app/Services/Moderation/ModerationCaseService.php
- app/Services/Feedback/FeedbackService.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php
- app/Http/Controllers/Api/User/Notifications/NotificationEmailPreferenceController.php
- app/Models/Core/Site/Site.php
- app/Providers/AppServiceProvider.php (Gate::policy registrations verified)
- app/Enums/AccountType.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#CAP-1** · P1 — `StaffUserController` crashes on every request after skeleton-system strip removed `Site::theme()`
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:35, :61, :97, :105–108
    - **Affects:** All staff — `GET /api/staff/professionals` (user list) and `GET /api/staff/professionals/{id}` (detail) both throw a `BadMethodCallException` on every request. The primary staff user management interface is non-functional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `->with(['site.theme'])` from `StaffUserController::index()`; change to `->with(['site'])`.
        - Remove `$professional->load(['site.theme', 'services', 'blocks'])` from `show()`; change to `->load(['site', 'services', 'blocks'])`.
        - Replace all `$site?->theme` / `$professional->site->theme` accesses in `index()` and `show()` with the new `skeleton_id` field: emit `'skeleton_id' => $site->skeleton_id` (already present in the `Site` model's fillable) instead of the `'theme'` payload block.
        - In the staff list/detail response, drop the `theme: {id, key, name}` sub-object; replace with `skeleton_id: string` to match the skeleton-system architecture (`StaffSiteController` already does this correctly).
    - **Technical:** The skeleton-system cleanup replaced `site.sites.theme_id` with `site.sites.skeleton_id` and removed the `theme()` Eloquent relationship from `Site` entirely. `StaffUserController` was not updated in that sweep. Laravel's eager-load mechanism calls `(new Site)->theme()` to resolve the nested relation constraints; since `Site::theme()` is undefined, Eloquent's `__call` forwards to the query builder which also has no `theme()` method, producing a `BadMethodCallException: Call to undefined method … Builder::theme()` on the first request. This is not caught anywhere in the HTTP stack, so Laravel returns a 500 to every caller. `StaffSiteController::buildPayload()` (the correct staff payload builder added during the skeleton migration) already emits `skeleton_id` and omits `theme`; `StaffUserController` must align to the same shape.
    - **Plain English:** During a recent redesign, the concept of "themes" was replaced with "skeletons" throughout the app. The new building block is `skeleton_id` (a simple code name like `skeleton-1`). The staff dashboard's user-list page still tries to load the old theme data that no longer exists — like asking a library for a book that was removed from the catalogue. Every time a staff member opens the user list or a user's profile page, the app crashes rather than showing the data. This needs to be patched before any staff member can use the dashboard.
    - **Evidence:**
        ```php
        // StaffUserController.php:35 — eager-loads Site::theme() which no longer exists
        $query = User::query()
            ->with(['site.theme'])
            ->orderByDesc('created_at');

        // StaffUserController.php:61 — property access would also throw
        $theme = $site?->theme;

        // StaffUserController.php:97 — show() has the same broken load
        $professional->load(['site.theme', 'services', 'blocks']);

        // Site.php — theme() relationship does not exist; skeleton_id is the replacement
        protected $fillable = [
            'subdomain',
            'skeleton_id',   // ← skeleton-system field, no theme_id
            'is_published',
            'unpublished_at',
            'settings',
            'moderation_state',
        ];
        // (no theme() method defined anywhere on this model)
        ```

---

## P3 — Nice to have

- [ ] **#CAP-2** · P3 — `notification_categories` capability declared but enforcement mechanism is absent; field meaning contradicts implementation
    - **Where:** app/Services/Accounts/AccountCapabilitySet.php:18–34; app/Services/Accounts/AccountCapabilities.php:44; app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php (CAPABILITY_GATE_MAP)
    - **Affects:** Future developers adding new notification categories — the docblock on `AccountCapabilitySet` implies `notification_categories: 'profile,platform'` restricts which email categories an account may receive, but no code path enforces this. A developer following the docblock's stated contract would incorrectly assume accounts without a given category in the list cannot receive emails in that category.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - **Do not** add a `notification_categories` check inside `SendTransactionalNotificationEmailJob::handle()` as a direct implementation of the docblock contract — doing so would immediately block currently-valid categories (`analytics_weekly`, `policy_update`, `incident`, `feature_announcement`) for all accounts, because none of those are in the `'profile,platform'` allowlist and none are in `CAPABILITY_GATE_MAP` either. That would be a regression, not a fix.
        - Instead, add a clear inline comment to `AccountCapabilitySet::$notification_categories` explaining that the enforcement mechanism is `SendTransactionalNotificationEmailJob::CAPABILITY_GATE_MAP`, not a runtime allowlist check against this field. Clarify that the field is a forward-declaration for the multi-account-type re-integration and is currently unused.
        - Add a matching comment to `CAPABILITY_GATE_MAP = []` noting that it is intentionally empty for the standalone-individual model and that `notification_categories` will become the populating source when brand/partner account types are reintroduced.
        - Optionally rename the field to `notification_categories_reserved` or add a `@internal` PHPDoc tag so static analysis catches accidental reads.
    - **Technical:** `AccountCapabilitySet::$notification_categories` is documented as "Comma-separated list of allowed categories. 'full' means every category in the registry." `AccountCapabilities::individualCapabilities()` sets it to `'profile,platform'` for all accounts. No consumer — not `SendTransactionalNotificationEmailJob`, not `NotificationEmailPreferenceController`, not `NotificationPublisher` — reads this field. The actual category-gating mechanism is `CAPABILITY_GATE_MAP = []` (intentionally empty for the standalone model), which makes all categories universally receivable. The gap is documentation/intent, not a current runtime bypass: all accounts are individual today, so the `'profile,platform'` value is neither enforced nor violated. The risk is that the first developer to add a new email category that should be restricted could misread the docblock and assume the field is already wired up, leading to a silent bypass of the intended restriction.
    - **Plain English:** Think of `notification_categories` as a guest list posted on the mailroom wall that says "only deliver profile and platform emails" — but the actual mailroom staff don't look at the list before delivering. Today that's fine because everyone gets the same types of mail anyway. But when new mail types are added in the future, a developer might assume the list is enforced and skip the extra wiring needed to actually enforce it. This finding asks us to add a note to the wall saying "this list isn't checked yet — see the CAPABILITY_GATE_MAP for how gating really works."
    - **Evidence:**
        ```php
        // AccountCapabilitySet.php — docblock states a contract that is never enforced
        /**
         * @param  string  $notification_categories  Comma-separated list of allowed categories.
         *                                           'full' means every category in the registry.
         */
        public function __construct(
            public bool $can_edit_design,
            public string $notification_categories,
            // ...
        ) {}
        ```
        ```php
        // AccountCapabilities.php — sets the field, but nothing ever reads it
        notification_categories: 'profile,platform',
        ```
        ```php
        // SendTransactionalNotificationEmailJob.php — actual gating mechanism; field is unused
        // grep across entire app/ returns zero reads of ->notification_categories
        private const CAPABILITY_GATE_MAP = [];  // intentionally empty for standalone model
        ```
