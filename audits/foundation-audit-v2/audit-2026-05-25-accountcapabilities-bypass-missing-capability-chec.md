I have everything I need. Let me summarize my findings before writing the audit:

**CAP-1 verification:**
- ✅ Evidence is accurate — `notification_categories` is set in `AccountCapabilities::individualCapabilities()` and never read by any application code (`grep` confirms zero other reads)
- ✅ `CAPABILITY_GATE_MAP = []` confirmed empty by design, with correct comment
- ✅ The symmetry contrast DeepSeek cited is real: `can_submit_feedback` IS properly enforced in both `FeedbackPolicy::create()` and `FeedbackService::submit()`, while `notification_categories` has zero enforcement
- **Re-tier P2 → P3**: No current user-facing correctness issue. The only categories in the `mailables` registry are `profile_tasks`, `policy_update`, `incident` — all appropriate for individual accounts post-strip. Additionally, the category names don't even match the `'profile,platform'` tokens, meaning naïve enforcement would hide all categories — this is a design decision, not a quick patch
- The proposed fix needs updating: naive `in_array()` against `explode(',', $notification_categories)` would silently hide all three current categories

**DeepSeek missed findings**: None that survive verification against this lens. All notification/API/job paths without capability gates carry accurate justification comments, and `AccountType` enum integrity is correct (single `Individual` case, no branching on removed types).

`★ Insight ─────────────────────────────────────`
- The Partna capability system follows two patterns: boolean gates (`can_submit_feedback`) enforced through Policies, and the unimplemented string-list gate (`notification_categories`) — the asymmetry is clearest in that `can_submit_feedback` is double-enforced (Policy + Service layer) while `notification_categories` has zero enforcement points
- The mismatch between `'profile,platform'` tokens and actual category slugs (`profile_tasks`, `policy_update`, `incident`) reveals this field was designed with a grouping/prefix concept in mind that was never implemented — a silent interface mismatch, not just dead code
- The 2026-05-22 standalone strip removed the enforcement code along with the commerce categories, leaving the property as scaffolding that *looks* active but does nothing
`─────────────────────────────────────────────────`

# AccountCapabilities Audit — 2026-05-25

**Branch:** development
**Lens:** AccountCapabilities bypass, missing capability checks before notification/API/job actions, AccountType enum integrity
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Accounts/AccountCapabilities.php`
- `app/Services/Accounts/AccountCapabilitySet.php`
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`
- `app/Jobs/Notifications/SendEnquiryNotificationJob.php`
- `app/Jobs/Notifications/SendFeedbackEmailJob.php`
- `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php`
- `app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php`
- `app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php`
- `app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php`
- `app/Http/Controllers/Api/Professional/Feedback/FeedbackController.php`
- `app/Policies/FeedbackPolicy.php`
- `app/Enums/AccountType.php`
- `config/partna.php` (notifications.mailables keys)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **#CAP-1** · P3 — `notification_categories` capability property is defined, assigned, and never read
    - **Where:** `app/Services/Accounts/AccountCapabilitySet.php:30` (property), `app/Services/Accounts/AccountCapabilities.php:44` (assignment), `app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php` (filtering), `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php` (dispatch guard)
    - **Affects:** Developer clarity and future correctness. No current user-visible behaviour is wrong — all three categories currently in `partna.notifications.mailables` (`profile_tasks`, `policy_update`, `incident`) are appropriate for individual accounts. However, the property creates a false contract: anyone reading `AccountCapabilitySet` will believe category access is gated; it is not.
    - **Effort:** S (~0.5–1h) to remove; M (~2–4h) to implement with correct grouping logic
    - **What to do:**
        - **Preferred (remove):** Delete the `notification_categories` parameter from `AccountCapabilitySet::__construct()` and its assignment in `AccountCapabilities::individualCapabilities()`. Re-add it with a working implementation when commerce/billing categories are reintegrated (the docblock already calls this out: "will be re-added as named params here when reintegrated").
        - **Alternative (implement):** If the property must stay as scaffolding, add an enforcement path in `NotificationEmailPreferenceController::index()` that filters `NotificationPublisher::categories()` against `$caps->notification_categories`. Use `'full'` as the pass-all sentinel (already documented in the docblock). Note that a naïve `in_array($category, explode(',', $caps->notification_categories))` against the current value `'profile,platform'` would hide **all three** current categories (`profile_tasks`, `policy_update`, `incident`) because none match those tokens exactly — the matching logic requires a group/prefix design to be agreed on first.
        - Update `tests/Feature/Account/AccountCapabilitiesTest.php` (currently only asserts the string value, not enforcement).
    - **Technical:** `AccountCapabilities::individualCapabilities()` sets `notification_categories: 'profile,platform'`. The two consumers that should enforce it — `NotificationEmailPreferenceController::index()` and `SendTransactionalNotificationEmailJob::handle()` — both use only `CAPABILITY_GATE_MAP` (intentionally empty after the 2026-05-22 standalone strip) and never read `$caps->notification_categories`. Contrast with `can_submit_feedback`, which is double-enforced in `FeedbackPolicy::create()` and `FeedbackService::submit()` — that is the correct pattern. A `grep` across the entire codebase confirms `notification_categories` is written in exactly two places and read nowhere. The category token mismatch (stored: `'profile'`, `'platform'`; registered: `'profile_tasks'`, `'policy_update'`, `'incident'`) means the enforcement design was never fully resolved before the strip removed the motivation for it.
    - **Plain English:** We have a settings field that says "this user is only allowed to receive notifications in these two categories." But that field is never actually checked — it's like writing a list of approved food items and then never showing it to the chef. Right now it doesn't matter because the only meals on the menu are already fine for everyone. But if we add new notification types when we bring commerce back, they'll silently bypass the restriction unless someone remembers this unfinished gate exists.
    - **Evidence:**
        ```php
        // AccountCapabilitySet.php — property defined but never read by any consumer
        /**
         * @param  string  $notification_categories  Comma-separated list of allowed categories.
         *                                           'full' means every category in the registry.
         */
        public function __construct(
            public bool $can_edit_design,
            public string $notification_categories,   // ← only assignment, zero reads
            public string $worker_kv_type,
            public bool $can_submit_feedback,
        ) {}

        // AccountCapabilities.php — hardcoded value that goes nowhere
        return new AccountCapabilitySet(
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
            can_submit_feedback: true,
        );

        // NotificationEmailPreferenceController.php — filters only via CAPABILITY_GATE_MAP,
        // never inspects $caps->notification_categories
        $caps = AccountCapabilities::for($pro);
        $gateMap = SendTransactionalNotificationEmailJob::capabilityGateMap();
        $visibleCategories = array_filter(
            NotificationPublisher::categories(),
            static function (string $category) use ($caps, $gateMap): bool {
                $cap = $gateMap[$category] ?? null;
                return $cap === null || $caps->{$cap};
            }
        );

        // SendTransactionalNotificationEmailJob.php — gate map is intentionally empty;
        // notification_categories is never consulted as an alternative path
        private const CAPABILITY_GATE_MAP = [];
        ```
