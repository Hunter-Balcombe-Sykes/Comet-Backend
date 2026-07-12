# Retire `account_type='staff'` — Option A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `core.partna_staff` the single source of truth for staff identity and powers by removing the parallel, unwired `account_type='staff'` concept and its dead capability layer.

**Architecture:** Staff authentication/authorization already runs entirely through the `staff`/`staff.admin` middleware (which reads `core.partna_staff.role`) and the staff Policies (which receive a `PartnaStaff` actor). The `account_type='staff'` value on `core.users` and the `is_staff` / `staff_*` / `can_have_site` / `can_connect_integrations` capabilities are a second representation that **no runtime code consumes** — only tests and "keep in sync" comments reference them. This plan deletes that second representation top to bottom: a Postgres migration converts the 3 existing staff user-rows to `'partna'` and re-narrows the CHECK constraint, and the PHP changes strip the enum case, model accessor, capability branch, and the two request/controller allowlists that accepted `'staff'`.

**Tech Stack:** PHP 8.2 / Laravel 12, PostgreSQL (Supabase, dev ref `glncumufgaqcmqhzwrxm`), Pest 4 (SQLite in-memory), raw-SQL migrations in `supabase/migrations/`.

## Global Constraints

- **No Laravel migration files.** All schema changes are raw SQL in `supabase/migrations/` (composer guard `guard:no-laravel-migrations` rejects Laravel migrations).
- **`core.partna_staff` is untouched.** The table, `PartnaStaff` model, `EnsurePartnaStaff`/`EnsurePartnaAdmin` middleware, and all staff Policies **stay exactly as-is** — they are the sole source of truth we are consolidating onto. Do not modify their behavior.
- **A staff member MAY also hold a normal `partna`/`business` account.** After this change the two facts (is-a-Partna-user / is-internal-staff) are independent; `account_type` no longer encodes staff-ness. The 3 converted rows (`tobias`, `joshhunter`, `staff-test`) keep their handles and their `partna_staff` rows.
- **Dev only.** Prod is on the pre-standalone schema (latest applied `20260512145025`); it never had `account_type='staff'`. This migration is applied to the **dev** Supabase project `glncumufgaqcmqhzwrxm` only. Do not push to prod.
- **Verify on the full suite.** Removing shared types (`AccountCapabilitySet`) can break distant callers; run `composer test` (not a filtered subset) as the gate for every code task.
- **Keep the legacy `AccountType::Individual` case.** It is a safe-casting fallback, unrelated to staff — do not remove it.

---

## File Structure

**Migration (create):**
- `supabase/migrations/20260712000000_retire_staff_account_type.sql` — convert `staff`→`partna`, re-narrow the `users_account_type_check` CHECK to `('partna','business')`.

**Code (modify):**
- `app/Enums/AccountType.php` — remove `case Staff`; rewrite docblock.
- `app/Models/Core/User/User.php` — remove `isStaff()`.
- `app/Services/Accounts/AccountCapabilities.php` — remove the staff branch + `staffCapabilities()`/`staffPowers()`/`staffRole()` + the `PartnaStaff` import; update class docblock.
- `app/Services/Accounts/AccountCapabilitySet.php` — remove the 10 staff constructor params + their doc block.
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` — drop `'staff'` from the `account_type` filter allowlist.
- `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php` — drop `'staff'` from the `filters.account_type` `Rule::in`.
- `app/Policies/UserSegmentPolicy.php`, `EarlyAccessSignupPolicy.php`, `FeatureAvailabilityPolicy.php`, `FeedbackPolicy.php` — replace stale docblock references to the deleted `AccountCapabilities::staffPowers()`.

**Tests (modify / delete):**
- `tests/Feature/Accounts/StaffAccountCapabilitiesTest.php` — **delete** (tests removed behavior).
- `tests/Feature/Staff/StaffUserSearchFiltersTest.php` — rewrite the account-type-filter test to assert `'staff'` is no longer an accepted filter value.

**Docs / ground-truth (conditional):**
- `AI_CONTEXT.md`, `scripts/audit/system-prompt.md`, `scripts/audit/adjudicate-prompt.md` — grep for staff-as-account-type prose; update only if present.

---

### Task 1: Migration — convert staff rows + re-narrow the CHECK

**Files:**
- Create: `supabase/migrations/20260712000000_retire_staff_account_type.sql`

**Interfaces:**
- Consumes: nothing.
- Produces: a `core.users` table whose `users_account_type_check` allows only `('partna','business')` and whose 3 former-staff rows are now `account_type='partna'`. Later PHP tasks rely on `'staff'` no longer being a valid stored value.

- [ ] **Step 1: Write the migration SQL**

Create `supabase/migrations/20260712000000_retire_staff_account_type.sql`:

```sql
-- 20260712000000_retire_staff_account_type.sql
--
-- Option A: retire the 'staff' account type. Internal staff identity + powers
-- live solely in core.partna_staff (role support/admin), gated by the `staff`
-- middleware and the staff Policies. account_type no longer encodes staff-ness.
--
-- The 3 existing account_type='staff' rows (tobias, joshhunter, staff-test) each
-- have a matching core.partna_staff row. Convert them to 'partna': they remain a
-- normal Partna user (keeping handle + any site) AND remain staff via
-- partna_staff. Their partna_staff rows are intentionally left untouched.
--
-- Reverses 20260711000000_staff_account_type.sql. Same DROP → ADD NOT VALID →
-- VALIDATE dance (CONVENTIONS §2); after the UPDATE no 'staff' rows remain so
-- VALIDATE is a clean pass.

-- 1. Demote the internal-staff user rows back to the standard account type.
UPDATE core.users SET account_type = 'partna' WHERE account_type = 'staff';

-- 2. Re-narrow the CHECK to the two user-selectable types.
ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

ALTER TABLE core.users
    ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business')) NOT VALID;

ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;
```

- [ ] **Step 2: Dry-run against dev**

Run: `supabase db push --dry-run` (after `supabase link --project-ref glncumufgaqcmqhzwrxm`, which Josh runs interactively with the `!` prefix).
Expected: the dry-run lists `20260712000000_retire_staff_account_type.sql` as the only pending migration.

Alternative (dev is fine to iterate on directly): apply via the Supabase MCP `apply_migration` against project `glncumufgaqcmqhzwrxm` with name `retire_staff_account_type` and the SQL body above.

- [ ] **Step 3: Apply to dev**

Run: `supabase db push` (or MCP `apply_migration`).
Expected: success, no constraint-validation error.

- [ ] **Step 4: Verify post-state on dev**

Run this SQL against `glncumufgaqcmqhzwrxm` (MCP `execute_sql`):

```sql
SELECT account_type, count(*) FROM core.users GROUP BY account_type ORDER BY account_type;
SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'users_account_type_check';
SELECT count(*) FROM core.partna_staff;
```

Expected:
- No `staff` row in the first result (only `partna` — now 10 — and `business` — 1).
- Constraint def = `CHECK ((account_type = ANY (ARRAY['partna'::text, 'business'::text])))`.
- `core.partna_staff` count still **3** (untouched — the 3 people are still staff).

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260712000000_retire_staff_account_type.sql
git commit -m "feat(accounts): retire account_type='staff' — partna_staff is sole source of truth (migration)"
```

---

### Task 2: Strip the staff layer from the capability service

**Files:**
- Modify: `app/Services/Accounts/AccountCapabilities.php`
- Modify: `app/Services/Accounts/AccountCapabilitySet.php`
- Delete: `tests/Feature/Accounts/StaffAccountCapabilitiesTest.php`

**Interfaces:**
- Consumes: `User::isBusiness()` (unchanged), `User::isStaff()` (still exists after this task; removed in Task 3).
- Produces: `AccountCapabilitySet` with **no** `is_staff` / `can_have_site` / `can_connect_integrations` / `staff_*` properties; `AccountCapabilities::for(User)` returns only the individual capability set. Later tasks rely on these properties no longer existing.

> This task removes dead code (verified: no runtime consumer reads any staff capability property). The guard is "full suite green after removal", not red-green.

- [ ] **Step 1: Delete the dead capability test**

The entire file tests removed behavior (`is_staff`, `staff_*`, `can_have_site`).

```bash
git rm tests/Feature/Accounts/StaffAccountCapabilitiesTest.php
```

- [ ] **Step 2: Rewrite `AccountCapabilities.php`**

Replace the whole file with:

```php
<?php

namespace App\Services\Accounts;

use App\Models\Core\User\User;

/**
 * Runtime capability registry — answers "can this account access feature X right now?"
 * Most capabilities are constant; `can_book_storewide`, the Google Business sync
 * flags, and `can_use_multipage_site` derive from `account_type` — this is the
 * single sanctioned place that reads the type; everything else gates on the
 * derived capability. (Internal staff are NOT an account type — see PartnaStaff.)
 */
final class AccountCapabilities
{
    /**
     * Per-account memoization (audit SCALE-1). WeakMap so memoized instances
     * don't pin the account alive longer than necessary.
     */
    private static ?\WeakMap $cache = null;

    public static function for(User $pro): AccountCapabilitySet
    {
        self::$cache ??= new \WeakMap;
        if (isset(self::$cache[$pro])) {
            return self::$cache[$pro];
        }

        $set = self::individualCapabilities($pro);
        self::$cache[$pro] = $set;

        return $set;
    }

    /** Flush the per-instance cache. Tests call this when reassigning fields on a memoized account. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function individualCapabilities(User $pro): AccountCapabilitySet
    {
        $status = (string) ($pro->status ?? '');

        return new AccountCapabilitySet(
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
            can_submit_feedback: true,
            can_be_reported: $status === 'active',
            receive_moderation_notifications: in_array($status, ['active'], true),
            can_book_storewide: $pro->isBusiness(),
            google_business_full_sync: $pro->isBusiness(),
            google_business_sets_display_name: $pro->isBusiness(),
            can_use_multipage_site: $pro->isBusiness(),
        );
    }
}
```

- [ ] **Step 3: Remove the staff params from `AccountCapabilitySet.php`**

Delete the staff block from the constructor. Replace this text:

```php
        public bool $can_use_multipage_site,
        // ── Staff accounts (OV-A) ───────────────────────────────────────────
        // True for account_type='staff' (internal Partna staff). Staff accounts
        // have NO site and NO integrations — can_have_site / can_connect_
        // integrations flip false so bootstrap + connect paths refuse without
        // branching on the type. The staff_* powers below are granular and
        // derive from the linked core.partna_staff role (support = view-level,
        // admin = view + manage) — see AccountCapabilities::staffPowers().
        public bool $is_staff = false,
        public bool $can_have_site = true,
        public bool $can_connect_integrations = true,
        // Manage powers (admin staff): user site/integration edits, segments,
        // feature availability, notification sends, early-access invites.
        public bool $staff_manage_users = false,
        public bool $staff_manage_segments = false,
        public bool $staff_manage_availability = false,
        public bool $staff_send_notifications = false,
        public bool $staff_manage_early_access = false,
        // View powers (any staff role): aggregate analytics + feedback review.
        public bool $staff_view_aggregate_analytics = false,
        public bool $staff_view_feedback = false,
    ) {}
```

with:

```php
        public bool $can_use_multipage_site,
    ) {}
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: PASS. (No consumer reads the removed properties; `individualCapabilities()` never passed them.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Accounts/AccountCapabilities.php app/Services/Accounts/AccountCapabilitySet.php
git commit -m "refactor(accounts): remove unwired staff capability layer (is_staff, staff_* powers)"
```

---

### Task 3: Remove the enum case and model accessor

**Files:**
- Modify: `app/Models/Core/User/User.php:115-121`
- Modify: `app/Enums/AccountType.php`

**Interfaces:**
- Consumes: nothing (after Task 2 the only caller of `isStaff()` — the capability branch — is gone).
- Produces: `AccountType` with cases `Partna`, `Business`, `Individual` only; no `User::isStaff()`. Later tasks rely on `AccountType::Staff` and `isStaff()` being undefined.

- [ ] **Step 1: Remove `isStaff()` from `User.php`**

Delete these lines (the accessor and its comment, immediately after `isBusiness()`):

```php
    // Internal staff account (no site, no integrations). Like isBusiness(),
    // this is read ONLY inside AccountCapabilities — everything else gates on
    // the derived capabilities (is_staff + staff_* powers).
    public function isStaff(): bool
    {
        return $this->account_type === AccountType::Staff;
    }
```

- [ ] **Step 2: Rewrite `AccountType.php`**

Replace the whole file with:

```php
<?php

namespace App\Enums;

/**
 * Partna account type. Two user-selectable types:
 *
 *   - Partna   ('partna')   — the standard account; every pre-existing account.
 *   - Business ('business') — "Business Partna".
 *
 * Both behave identically except where AccountCapabilities says otherwise.
 *
 * Internal Partna staff are NOT an account type — staff identity + powers live
 * solely in core.partna_staff (role support/admin), gated by the `staff`
 * middleware and the staff Policies. A staff member may separately hold a
 * normal Partna/Business account; the two facts are independent.
 *
 * Individual ('individual') is a legacy value kept ONLY so Eloquent casting never
 * throws on a row read between the code deploy and the backfill migration
 * (20260612120000_account_type_partna_business). It is not user-selectable —
 * request validation rejects it.
 */
enum AccountType: string
{
    case Partna = 'partna';
    case Business = 'business';

    case Individual = 'individual';
}
```

- [ ] **Step 3: Confirm zero dangling references**

Run: `git grep -nE 'AccountType::Staff|->isStaff\(' app/ tests/`
Expected: **no output** (exit 1).

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/AccountType.php app/Models/Core/User/User.php
git commit -m "refactor(accounts): drop AccountType::Staff case + User::isStaff()"
```

---

### Task 4: Remove `'staff'` from the request/controller allowlists

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:54`
- Modify: `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php:19`
- Modify: `tests/Feature/Staff/StaffUserSearchFiltersTest.php`

**Interfaces:**
- Consumes: `StaffUserController::index` request (unchanged signature).
- Produces: `account_type=staff` is no longer an accepted filter/segment value — it is ignored like any unknown value.

- [ ] **Step 1: Rewrite the search-filter test (red first)**

In `tests/Feature/Staff/StaffUserSearchFiltersTest.php`, replace the `it('filters by account_type including staff', ...)` block:

```php
it('filters by account_type including staff', function () {
    ovaSearchUser(['account_type' => 'partna']);
    $biz = ovaSearchUser(['account_type' => 'business']);
    $staff = ovaSearchUser(['account_type' => 'staff']);

    expect(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'business'])))->toBe([$biz])
        ->and(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'staff'])))->toBe([$staff])
        ->and(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'bogus'])))->toHaveCount(3);
});
```

with (note: no `'staff'` row is inserted; `account_type=staff` is now an ignored value that returns all rows):

```php
it('filters by account_type; staff is no longer an accepted value', function () {
    ovaSearchUser(['account_type' => 'partna']);
    $biz = ovaSearchUser(['account_type' => 'business']);
    ovaSearchUser(['account_type' => 'partna']);

    expect(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'business'])))->toBe([$biz])
        // 'staff' is no longer an accepted filter → treated like any unknown value → ignored.
        ->and(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'staff'])))->toHaveCount(3)
        ->and(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'bogus'])))->toHaveCount(3);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Staff/StaffUserSearchFiltersTest.php --filter='no longer an accepted value'`
Expected: FAIL — the controller still filters on `'staff'`, so the `staff` request returns 0 rows, not 3.

- [ ] **Step 3: Drop `'staff'` from the controller allowlist**

In `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`, change:

```php
        $accountType = $request->query('account_type');
        if (is_string($accountType) && in_array($accountType, ['partna', 'business', 'staff'], true)) {
            $query->where('account_type', $accountType);
        }
```

to:

```php
        $accountType = $request->query('account_type');
        if (is_string($accountType) && in_array($accountType, ['partna', 'business'], true)) {
            $query->where('account_type', $accountType);
        }
```

- [ ] **Step 4: Drop `'staff'` from the segment filter rule**

In `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`, change:

```php
            'filters.account_type' => ['sometimes', 'nullable', 'string', Rule::in(['partna', 'business', 'staff'])],
```

to:

```php
            'filters.account_type' => ['sometimes', 'nullable', 'string', Rule::in(['partna', 'business'])],
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Staff/StaffUserSearchFiltersTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php \
        app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php \
        tests/Feature/Staff/StaffUserSearchFiltersTest.php
git commit -m "refactor(staff): stop accepting account_type=staff in user search + segment filters"
```

---

### Task 5: Clean up stale policy docblocks

**Files:**
- Modify: `app/Policies/UserSegmentPolicy.php:9-15`
- Modify: `app/Policies/EarlyAccessSignupPolicy.php:9-14`
- Modify: `app/Policies/FeatureAvailabilityPolicy.php:9-13`
- Modify: `app/Policies/FeedbackPolicy.php:59-64`

**Interfaces:**
- Consumes / Produces: nothing — comment-only. The policy *behavior* (reading `PartnaStaff->role`) is unchanged and correct; only the references to the now-deleted `AccountCapabilities::staffPowers()` are stale.

> Do not remove the `use App\Models\Core\Staff\PartnaStaff;` imports — the policy method signatures still type-hint `PartnaStaff`.

- [ ] **Step 1: Fix `UserSegmentPolicy.php` docblock**

Replace:

```php
/**
 * OV-A: segments are staff-only resources. User-actor methods deny everything
 * (defensive — a misconfigured non-staff route can't grant access); staff-actor
 * methods encode the role rule (support = view, admin = manage), mirroring
 * AccountCapabilities::staffPowers() — keep the two in sync.
 */
```

with:

```php
/**
 * OV-A: segments are staff-only resources. User-actor methods deny everything
 * (defensive — a misconfigured non-staff route can't grant access); staff-actor
 * methods encode the role rule (support = view, admin = manage). The
 * core.partna_staff role is the sole source of truth for staff powers.
 */
```

- [ ] **Step 2: Fix `EarlyAccessSignupPolicy.php` docblock**

Replace:

```php
 * staff-actor methods encode support=view / admin=manage+invite, mirroring
 * AccountCapabilities::staffPowers() (staff_manage_early_access).
 */
```

with:

```php
 * staff-actor methods encode support=view / admin=manage+invite, gated on the
 * core.partna_staff role (the sole source of truth for staff powers).
 */
```

- [ ] **Step 3: Fix `FeatureAvailabilityPolicy.php` docblock**

Replace:

```php
 * OV-A: feature-availability rules are staff-only. Deny-all for User actors;
 * staff-actor methods encode support=view / admin=manage, mirroring
 * AccountCapabilities::staffPowers() (staff_manage_availability).
 */
```

with:

```php
 * OV-A: feature-availability rules are staff-only. Deny-all for User actors;
 * staff-actor methods encode support=view / admin=manage, gated on the
 * core.partna_staff role (the sole source of truth for staff powers).
 */
```

- [ ] **Step 4: Fix `FeedbackPolicy.php` docblock**

Replace:

```php
    /**
     * OV-D: staff triage list (GET /staff/feedback) — any staff role, support
     * or admin. Mirrors EarlyAccessSignupPolicy::staffView / UserSegmentPolicy
     * ::staffView exactly, matching AccountCapabilities::staffPowers()'s
     * `staff_view_feedback: $isStaffRole` rule — keep the two in sync.
     */
```

with:

```php
    /**
     * OV-D: staff triage list (GET /staff/feedback) — any staff role, support
     * or admin. Mirrors EarlyAccessSignupPolicy::staffView / UserSegmentPolicy
     * ::staffView exactly; gated on the core.partna_staff role (the sole source
     * of truth for staff powers).
     */
```

- [ ] **Step 5: Confirm no `staffPowers` references remain**

Run: `git grep -n 'staffPowers\|staffCapabilities\|staffRole' app/`
Expected: **no output** (exit 1).

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS (comment-only change, no behavior difference).

- [ ] **Step 7: Commit**

```bash
git add app/Policies/UserSegmentPolicy.php app/Policies/EarlyAccessSignupPolicy.php \
        app/Policies/FeatureAvailabilityPolicy.php app/Policies/FeedbackPolicy.php
git commit -m "docs(policies): drop stale references to deleted AccountCapabilities::staffPowers()"
```

---

### Task 6: Ground-truth sweep + final verification

**Files:**
- Modify (conditional): `AI_CONTEXT.md`, `scripts/audit/system-prompt.md`, `scripts/audit/adjudicate-prompt.md`

**Interfaces:**
- Consumes / Produces: nothing — documentation + final gate.

- [ ] **Step 1: Grep the architecture ground-truth docs for staff-as-account-type prose**

Run: `git grep -niE "account.?type.*staff|staff.*account.?type|AccountType::Staff" AI_CONTEXT.md scripts/audit/system-prompt.md scripts/audit/adjudicate-prompt.md`
- If any hit describes `'staff'` as an account type or third `account_type` value, edit that prose to say internal staff live in `core.partna_staff` and are not an account type.
- If no output: nothing to change (CLAUDE.md already documents `account_type` as `'partna'`/`'business'` only — leave it).

- [ ] **Step 2: Full dangling-reference sweep**

Run:

```bash
git grep -nE 'AccountType::Staff|->isStaff\(|staffPowers|staffCapabilities|staffRole|is_staff|can_have_site|can_connect_integrations|staff_manage_|staff_view_|staff_send_' app/ tests/
```

Expected: **no output** (exit 1). (These tokens are distinct from `partna_staff`, `PartnaStaff`, `staff.audit`, `staff:admin`, and the `StaffSite`/`Staff\` namespaces, which are the real, retained staff system and must NOT be removed.)

- [ ] **Step 3: Final full suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 4: Commit any doc edits (skip if Step 1 found nothing)**

```bash
git add AI_CONTEXT.md scripts/audit/system-prompt.md scripts/audit/adjudicate-prompt.md
git commit -m "docs: clarify internal staff are not an account type (partna_staff is source of truth)"
```

---

## Self-Review

**Spec coverage** (against the Option A decision):
- Convert 3 existing staff rows to `'partna'` → Task 1 Step 1.
- Re-narrow the CHECK constraint → Task 1 Steps 1, 4.
- Remove the enum case → Task 3.
- Remove `User::isStaff()` → Task 3.
- Remove the dead capability staff-layer (`is_staff`, `staff_*`, `can_have_site`, `can_connect_integrations`) → Task 2.
- Remove `'staff'` from the two write allowlists (staff user-search, segment filter) → Task 4.
- Fix stale comments referencing the deleted method → Task 5.
- Delete the test that pins removed behavior → Task 2 Step 1; update the search-filter test → Task 4.
- Ground-truth docs + final gate → Task 6.
- `core.partna_staff` / middleware / policies left intact → Global Constraints + Task 5 note. ✔ every requirement maps to a task.

**Placeholder scan:** none — every code step shows exact before/after text and exact commands.

**Type consistency:** `AccountCapabilitySet` loses exactly the 10 named params that `staffCapabilities()` (deleted) passed; `individualCapabilities()` passes only the 10 retained params, so its `new AccountCapabilitySet(...)` call still type-checks. `AccountType` retains `Partna`/`Business`/`Individual`; `isBusiness()` (used by `individualCapabilities()`) is untouched. Grep guards in Tasks 3, 5, 6 prove no symbol outlives its definition.

**Risk notes:**
- Tests run on SQLite where CHECK constraints aren't enforced — so the migration's constraint change is verified against real Postgres in Task 1 Step 4, not the suite.
- The `staff` / `staff.admin` middleware and staff Policies are deliberately out of scope; they already are (and remain) the single enforcement path.
