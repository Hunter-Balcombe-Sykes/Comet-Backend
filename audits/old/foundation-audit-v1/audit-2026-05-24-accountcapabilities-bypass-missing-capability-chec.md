`★ Insight ─────────────────────────────────────`
PHP backed enums (`enum Foo: string`) throw `ValueError` on `from()` at hydration time — the User model's `$casts = ['account_type' => AccountType::class]` means Eloquent calls `AccountType::from($value)` on every `User::find()`. A single stale DB row silently turns into a 500 with no useful error message. AllSiteData (the view) has no such cast, so it would just return a raw string — an inconsistency worth noting.
`─────────────────────────────────────────────────`

# AccountCapabilities — Audit 2026-05-24

**Branch:** development
**Lens:** AccountCapabilities bypass, missing capability checks before notification/API/job actions, AccountType enum integrity
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Enums/AccountType.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Models/Core/Professional/User.php (cast verification)
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
- All controller source files provided

## Progress

- P0 Blockers: 0 of 0 complete
- P2 Medium: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#CAP-1** · P2 — Single-case AccountType enum will crash on any stale `account_type` row left in the dev/test database after the standalone strip
    - **Where:** app/Enums/AccountType.php:1 · app/Models/Core/Professional/User.php:91
    - **Affects:** Any request that loads a `User` row whose `account_type` column is not `'individual'` — covers auth middleware, dashboard endpoints, staff views, and the scheduler. Limited to environments (development, CI) that held data before the strip; the production Supabase project is fresh per CLAUDE.md.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Run a one-off SQL statement in every non-fresh environment: `UPDATE core.users SET account_type = 'individual' WHERE account_type != 'individual';` and confirm zero rows remain.
        - Add a `CHECK (account_type = 'individual')` constraint to `core.users.account_type` in a `supabase/migrations/` file so the database itself rejects any future non-conforming value before PHP ever sees it.
        - Optionally add a `try/catch ValueError` in the `User` accessor with a `Log::error` and a forced `Individual` fallback — this makes the symptom observable in Nightwatch rather than a silent 500 during incident investigation.
    - **Technical:** `User::$casts` maps `account_type` to `AccountType::class`, causing Eloquent to call `AccountType::from($value)` on hydration. PHP backed enums throw `ValueError` for any value not present in the enum, and the `brand`/`affiliate` cases were removed in commit `59e57735` without a corresponding DB data migration. The baseline migration (`20260526000000_baseline_standalone_user.sql`) consolidates schema but its contents weren't provided — the strip task notes do not confirm a data-level `UPDATE` was issued. Production is safe (fresh project); dev and CI are at risk until explicitly cleaned.
    - **Plain English:** We redesigned the system to have only one type of account ("individual"), then removed the other types from the code — but may not have updated the old test records still sitting in the development database. When the app tries to load one of those old records, it looks up the account type in a list that no longer contains "brand" or "affiliate", and crashes with a confusing error. The fix is to update any old records in the database to say "individual", and add a database-level guard so a bad value can never sneak in again.
    - **Evidence:**
        ```php
        // app/Enums/AccountType.php
        enum AccountType: string
        {
            case Individual = 'individual';
        }

        // app/Models/Core/Professional/User.php
        protected $casts = [
            // ...
            'account_type' => AccountType::class,  // throws ValueError for any non-'individual' row
        ```

- [ ] **#CAP-2** · P2 — `SendTransactionalNotificationEmailJob` dispatches email to suspended/disabled professionals without a status guard
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:handle()
    - **Affects:** Any professional whose account is `suspended` or `disabled` but not yet soft-deleted — they can still receive platform transactional emails (profile updates, platform announcements) if a notification targeting them is dispatched while the account is non-active.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the capability gate check (and before the `NotificationPublisher::resolveEmailEnabled` preference lookup), add a status check: if the professional's `status` is not `'active'`, log a debug entry and return early.
        - Preserve the current fail-closed behavior for hard-deleted users (the existing `whereNull('deleted_at')` query already handles this); the new check adds the soft-suspended case.
    - **Technical:** The job loads the professional only when a `CAPABILITY_GATE_MAP` property is set (currently empty `[]`). When no capability gate applies, the professional object is never loaded before the email query fires — meaning `status` is never checked. The `primary_email` lookup at the bottom uses only `whereNull('deleted_at')`, which correctly excludes hard-deleted rows but passes through `suspended` and `disabled` accounts. Adding an explicit `where('status', 'active')` to that query (or an early-exit check after a `User::find()`) closes the gap.
    - **Plain English:** The system sends notification emails but only checks whether the recipient's account has been completely removed — it doesn't check whether the account is merely suspended or disabled. A suspended member could still get "your profile was updated" emails, which is confusing for them and may conflict with the expectation that suspending an account cuts off all platform communications. The fix is a quick status check before sending: if the account isn't active, skip the email and log why.
    - **Evidence:**
        ```php
        $email = DB::table('core.users')
            ->where('id', $this->professionalId)
            ->whereNull('deleted_at')  // excludes hard-deleted; does NOT exclude suspended/disabled
            ->value('primary_email');
        ```
