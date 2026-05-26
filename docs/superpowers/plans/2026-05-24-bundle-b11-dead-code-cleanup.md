# Bundle B11: Post-Strip Dead Code Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove 8 artefacts left orphaned by the 2026-05-22 standalone strip — config references to dropped schemas, dead PHP classes/methods, a stripped constant, and a broken seed script.

**Architecture:** All changes are pure deletions or one-line config fixes, plus two SQL migrations (an AccountType CHECK constraint and an unselect_product row purge). No new abstractions. No blast radius beyond the files listed.

**Tech Stack:** PHP 8.2 / Laravel 12, PostgreSQL (Supabase), Pest 4

---

## Pre-flight note: P3-07 already done

`SectionVisibilityService::professionalHasBookingIntegration()` already contains the comment
`// Smart-booking (Square/Fresha integration) has been dropped — always false.`
No action required for P3-07.

---

## Files Modified / Deleted

| File | Action | Audit item |
|------|--------|-----------|
| `config/database.php` | Modify default `search_path` | P2-17 |
| `supabase/config.toml` | Remove `billing`/`retail` from schemas | P3-17 |
| `app/Exceptions/NoRecipientEmailException.php` | **Delete** | P3-10 |
| `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php` | Remove `store()` method | P3-06 |
| `routes/api/professional.php` | Remove `POST /gallery` route | P3-06 |
| `app/Services/Professional/ConfirmationPreferenceService.php` | Remove `ACTION_UNSELECT_PRODUCT` + `defaultMap` entry | P2-31 |
| `app/Services/Accounts/AccountCapabilitySet.php` | Remove 15 always-false constructor params | P3-08 |
| `app/Services/Accounts/AccountCapabilities.php` | Remove 15 corresponding named args | P3-08 |
| `app/Http/Resources/ProfessionalStaffResource.php` | Hardcode `requires_stripe_connect: false` | P3-08 |
| `tests/Feature/Account/AccountCapabilitiesTest.php` | Remove assertions for deleted properties | P3-08 |
| `supabase/migrations/20260524200000_accounttype_check_constraint.sql` | **Create** — UPDATE + CHECK | P2-13 |
| `supabase/migrations/20260524200001_purge_unselect_product_prefs.sql` | **Create** — DELETE orphan rows | P2-31 |
| `supabase/seed.sql` | **Rewrite** for surviving `core.users` table | P3-16 |

---

## Task 1: Config cleanup — DB search path + config.toml schemas (P2-17 + P3-17)

**Files:**
- Modify: `config/database.php` (line 97)
- Modify: `supabase/config.toml` (lines 13–15)

- [ ] **Step 1.1: Fix database.php default search_path**

In `config/database.php` find line 97:
```php
'search_path' => env('DB_SEARCH_PATH', 'public,core,site,brand,commerce,notifications,analytics,billing'),
```

Replace with:
```php
'search_path' => env('DB_SEARCH_PATH', 'public,core,site,notifications,analytics'),
```

- [ ] **Step 1.2: Fix supabase/config.toml**

Find lines 13–15 in `supabase/config.toml`:
```toml
schemas = ["public", "graphql_public", "core", "analytics", "billing", "retail"]
# Extra schemas to add to the search_path of every request.
extra_search_path = ["public", "extensions", "core", "analytics", "billing", "retail"]
```

Replace with:
```toml
schemas = ["public", "graphql_public", "core", "site", "analytics", "notifications"]
# Extra schemas to add to the search_path of every request.
extra_search_path = ["public", "extensions", "core", "site", "analytics", "notifications"]
```

- [ ] **Step 1.3: Run tests to confirm no regression**

```bash
composer test
```

Expected: all tests green.

- [ ] **Step 1.4: Commit**

```bash
git add config/database.php supabase/config.toml
git commit -m "fix(B11/P2-17,P3-17): remove dropped schemas from DB search_path and config.toml"
```

---

## Task 2: Delete dead exception class (P3-10)

**Files:**
- Delete: `app/Exceptions/NoRecipientEmailException.php`

- [ ] **Step 2.1: Verify zero callers**

```bash
grep -rn "App\\\\Exceptions\\\\NoRecipientEmailException" app/ tests/ --include="*.php"
```

Expected output: **empty** (all callers use `App\Exceptions\Gdpr\NoRecipientEmailException`).

If anything is returned, do NOT delete — investigate first.

- [ ] **Step 2.2: Delete the file**

```bash
rm app/Exceptions/NoRecipientEmailException.php
```

- [ ] **Step 2.3: Run tests**

```bash
composer test
```

Expected: all green.

- [ ] **Step 2.4: Commit**

```bash
git add -A app/Exceptions/NoRecipientEmailException.php
git commit -m "fix(B11/P3-10): delete dead NoRecipientEmailException (callers use Gdpr variant)"
```

---

## Task 3: Remove gallery store() 410 stub (P3-06)

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php`
- Modify: `routes/api/professional.php`

- [ ] **Step 3.1: Remove route**

In `routes/api/professional.php`, find and delete this line:
```php
Route::post('/gallery', [ProfessionalGalleryController::class, 'store']); // deprecated → 410
```

- [ ] **Step 3.2: Remove store() method from controller**

In `ProfessionalGalleryController.php`, delete the entire `store()` method (lines 54–66):
```php
    /**
     * Gallery uploads are now handled by POST /api/uploads with pool=gallery.
     *
     * @deprecated Use POST /api/uploads with pool=gallery instead.
     */
    public function store(): JsonResponse
    {
        return $this->error(
            'Gallery image creation has moved to POST /api/uploads with pool=gallery. '
            .'Upload the image file directly instead of passing bucket/path.',
            410,
        );
    }
```

- [ ] **Step 3.3: Run tests**

```bash
composer test
```

Expected: all green (no test should call the 410 stub).

- [ ] **Step 3.4: Commit**

```bash
git add app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php routes/api/professional.php
git commit -m "fix(B11/P3-06): remove permanent 410 gallery store() stub and route"
```

---

## Task 4: Remove unselect_product from ConfirmationPreferenceService + DB migration (P2-31)

**Files:**
- Modify: `app/Services/Professional/ConfirmationPreferenceService.php`
- Create: `supabase/migrations/20260524200001_purge_unselect_product_prefs.sql`

- [ ] **Step 4.1: Update ConfirmationPreferenceService.php**

Replace the entire top-of-class constant block and `defaultMap()`:

**Before:**
```php
    public const ACTION_DELETE_CUSTOMER = 'delete_customer';

    public const ACTION_DELETE_MEDIA = 'delete_media';

    public const ACTION_UNSELECT_PRODUCT = 'unselect_product';

    public const SUPPORTED_ACTIONS = [
        self::ACTION_DELETE_CUSTOMER,
        self::ACTION_DELETE_MEDIA,
        self::ACTION_UNSELECT_PRODUCT,
    ];
```

**After:**
```php
    public const ACTION_DELETE_CUSTOMER = 'delete_customer';

    public const ACTION_DELETE_MEDIA = 'delete_media';

    public const SUPPORTED_ACTIONS = [
        self::ACTION_DELETE_CUSTOMER,
        self::ACTION_DELETE_MEDIA,
    ];
```

- [ ] **Step 4.2: Update docblocks and defaultMap()**

The `getForProfessional()`, `updateForProfessional()`, and `defaultMap()` docblocks reference `unselect_product`. Update them:

**getForProfessional() docblock — before:**
```php
     * @return array{delete_customer: bool, delete_media: bool, unselect_product: bool}
```
**After:**
```php
     * @return array{delete_customer: bool, delete_media: bool}
```

**updateForProfessional() docblock — before:**
```php
     * @return array{delete_customer: bool, delete_media: bool, unselect_product: bool}
```
**After:**
```php
     * @return array{delete_customer: bool, delete_media: bool}
```

**defaultMap() — before:**
```php
    /**
     * @return array{delete_customer: bool, delete_media: bool, unselect_product: bool}
     */
    private function defaultMap(): array
    {
        return [
            self::ACTION_DELETE_CUSTOMER => false,
            self::ACTION_DELETE_MEDIA => false,
            self::ACTION_UNSELECT_PRODUCT => false,
        ];
    }
```
**After:**
```php
    /**
     * @return array{delete_customer: bool, delete_media: bool}
     */
    private function defaultMap(): array
    {
        return [
            self::ACTION_DELETE_CUSTOMER => false,
            self::ACTION_DELETE_MEDIA => false,
        ];
    }
```

Also update the class-level comment at line 8:
**Before:**
```php
// V2: Manages per-professional "skip confirmation" preferences for destructive actions (delete customer, delete media, unselect product).
```
**After:**
```php
// V2: Manages per-professional "skip confirmation" preferences for destructive actions (delete customer, delete media).
```

- [ ] **Step 4.3: Write DB migration**

Create `supabase/migrations/20260524200001_purge_unselect_product_prefs.sql`:

```sql
-- B11/P2-31: Remove orphaned 'unselect_product' confirmation preference rows.
-- The product-selection feature was removed in the 2026-05-22 standalone strip.
-- Any rows with action_key = 'unselect_product' are dead data and are never read.

DELETE FROM core.professional_confirmation_preferences
WHERE action_key = 'unselect_product';
```

- [ ] **Step 4.4: Run tests**

```bash
composer test
```

If any test references `ACTION_UNSELECT_PRODUCT` or `unselect_product`, update it now to remove that case.

- [ ] **Step 4.5: Commit**

```bash
git add app/Services/Professional/ConfirmationPreferenceService.php \
        supabase/migrations/20260524200001_purge_unselect_product_prefs.sql
git commit -m "fix(B11/P2-31): remove ACTION_UNSELECT_PRODUCT constant and purge DB rows"
```

---

## Task 5: AccountType CHECK constraint migration (P2-13)

**Files:**
- Create: `supabase/migrations/20260524200000_accounttype_check_constraint.sql`

The `AccountType` enum now has only one case (`Individual = 'individual'`). Any `core.users` row with a different value will throw `ValueError` at runtime. The migration normalises stale rows first, then locks in the invariant with a CHECK.

- [ ] **Step 5.1: Write the migration**

Create `supabase/migrations/20260524200000_accounttype_check_constraint.sql`:

```sql
-- B11/P2-13: Enforce account_type = 'individual' on core.users.
-- The standalone strip (2026-05-22) reduced AccountType enum to a single case.
-- Step 1: normalise any stale rows from pre-strip environments.
-- Step 2: add CHECK constraint so the DB rejects future violations.

BEGIN;

-- 1. Normalise stale rows (safe no-op on fresh DBs).
UPDATE core.users
SET account_type = 'individual'
WHERE account_type IS DISTINCT FROM 'individual';

-- 2. Add the constraint (fail-fast if any row still violates after step 1).
ALTER TABLE core.users
    ADD CONSTRAINT users_account_type_individual
    CHECK (account_type = 'individual');

COMMIT;
```

- [ ] **Step 5.2: Run tests**

```bash
composer test
```

Expected: all green (tests use SQLite which won't apply the migration, so no breakage).

- [ ] **Step 5.3: Commit**

```bash
git add supabase/migrations/20260524200000_accounttype_check_constraint.sql
git commit -m "fix(B11/P2-13): normalise account_type rows + add CHECK constraint"
```

---

## Task 6: Slim AccountCapabilitySet — remove 15 dead constructor params (P3-08)

**Files:**
- Modify: `app/Services/Accounts/AccountCapabilitySet.php`
- Modify: `app/Services/Accounts/AccountCapabilities.php`
- Modify: `app/Http/Resources/ProfessionalStaffResource.php`
- Modify: `tests/Feature/Account/AccountCapabilitiesTest.php`

15 of 16 boolean constructor params are always `false`; only `can_edit_design` is ever true. The dead params make the constructor misleading and make adding real new capabilities noisier. Removing them contracts the API and deletes the noise.

`requires_stripe_connect` is read in `ProfessionalStaffResource` — fix that consumer before deleting the property.

- [ ] **Step 6.1: Fix ProfessionalStaffResource first**

Read `app/Http/Resources/ProfessionalStaffResource.php` around line 40. Find:
```php
AccountCapabilities::for($this->resource)->requires_stripe_connect,
```

Replace with:
```php
false, // Stripe Connect removed in 2026-05-22 standalone strip; reintegrate post-pilot
```

(Keep the surrounding array key so the API shape doesn't change for existing staff tooling.)

- [ ] **Step 6.2: Rewrite AccountCapabilitySet constructor**

Replace the entire file content of `app/Services/Accounts/AccountCapabilitySet.php`:

```php
<?php

namespace App\Services\Accounts;

/**
 * Snapshot of what a Professional can do RIGHT NOW. Built by {@see AccountCapabilities}.
 *
 * Read-only. Construct once per Professional per request and pass it around — capability
 * checks are pure functions on this value object so a single instance can be reused freely.
 *
 * Standalone-pages model: all accounts are individual. Only `can_edit_design` is true.
 * Capabilities for commerce/payout/brand features were removed in the 2026-05-22 strip
 * and will be re-added as named params here when reintegrated.
 *
 * @see docs/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-2.md §9
 */
final readonly class AccountCapabilitySet
{
    /**
     * @param  string  $notification_categories  Comma-separated list of allowed categories.
     *                                           'full' means every category in the registry.
     * @param  string  $worker_kv_type  Routing tag written to SUBDOMAIN_KV by
     *                                  SyncSubdomainToKvJob. One of: brand|affiliate|individual.
     */
    public function __construct(
        public bool $can_edit_design,
        public string $notification_categories,
        public string $worker_kv_type,
    ) {}
}
```

- [ ] **Step 6.3: Rewrite AccountCapabilities factory call**

Replace `individualCapabilities()` in `app/Services/Accounts/AccountCapabilities.php`:

```php
    private static function individualCapabilities(User $pro): AccountCapabilitySet
    {
        return new AccountCapabilitySet(
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
        );
    }
```

- [ ] **Step 6.4: Run tests (expect test failures — fix next)**

```bash
composer test 2>&1 | grep -A3 "FAILED\|Error"
```

Expected: `AccountCapabilitiesTest` will fail because it asserts the 15 removed properties.

- [ ] **Step 6.5: Update AccountCapabilitiesTest**

Read `tests/Feature/Account/AccountCapabilitiesTest.php`. Replace assertions for the deleted properties.

The test file currently asserts things like:
- `expect($this->caps->requires_stripe_connect)->toBeFalse();`
- `expect($this->caps->shows_shop_section)->toBeFalse();`
- etc. (15 such assertions)

Remove all assertions for the 15 deleted properties. Keep the assertions for:
- `can_edit_design` — should be `true`
- `notification_categories` — should be `'profile,platform'`
- `worker_kv_type` — should be `'individual'`

Also remove any `it()` blocks that exist solely to test the removed properties (e.g., `it('shows_ex_partner_panel is false for individuals...')`).

The remaining test structure should look like:
```php
it('individual professional has can_edit_design true', function () {
    expect($this->caps->can_edit_design)->toBeTrue();
});

it('individual professional notification_categories is profile,platform', function () {
    expect($this->caps->notification_categories)->toBe('profile,platform');
});

it('individual professional worker_kv_type is individual', function () {
    expect($this->caps->worker_kv_type)->toBe('individual');
});
```

- [ ] **Step 6.6: Run tests — all green**

```bash
composer test
```

Expected: all green.

- [ ] **Step 6.7: Commit**

```bash
git add app/Services/Accounts/AccountCapabilitySet.php \
        app/Services/Accounts/AccountCapabilities.php \
        app/Http/Resources/ProfessionalStaffResource.php \
        tests/Feature/Account/AccountCapabilitiesTest.php
git commit -m "fix(B11/P3-08): remove 15 always-false AccountCapabilitySet constructor params"
```

---

## Task 7: Rewrite seed.sql for surviving tables (P3-16)

**Files:**
- Modify: `supabase/seed.sql`

The current seed.sql is entirely dead: the guard at line 4 checks for `core.enterprises` (removed in the strip) and exits immediately. The seed data for enterprises, professionals (old table), and ambassador relationships no longer maps to the standalone model.

The rewrite seeds one test individual professional in `core.users` for local development.

- [ ] **Step 7.1: Replace supabase/seed.sql**

Overwrite the file with:

```sql
-- Standalone-pages seed: one test individual professional for local development.
-- Safe to run repeatedly (idempotent via deterministic UUID and upsert).
-- Updated 2026-05-24 after standalone strip (removed enterprise/ambassador scenarios).

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'users'
    ) THEN
        RAISE NOTICE 'Skipping seed: core.users table is missing. Run migrations first.';
        RETURN;
    END IF;

    -- ============================================================
    -- Test professional (individual)
    -- ============================================================
    INSERT INTO core.users (
        id,
        auth_user_id,
        handle,
        handle_lc,
        display_name,
        first_name,
        last_name,
        primary_email,
        account_type,
        status,
        onboarding_step
    )
    VALUES (
        '20000000-0000-0000-0000-000000000001',
        '30000000-0000-0000-0000-000000000001',
        'test-professional',
        'test-professional',
        'Test Professional',
        'Test',
        'Professional',
        'test@example.com',
        'individual',
        'active',
        0
    )
    ON CONFLICT (id) DO UPDATE
    SET
        handle         = EXCLUDED.handle,
        handle_lc      = EXCLUDED.handle_lc,
        display_name   = EXCLUDED.display_name,
        first_name     = EXCLUDED.first_name,
        last_name      = EXCLUDED.last_name,
        primary_email  = EXCLUDED.primary_email,
        account_type   = EXCLUDED.account_type,
        status         = EXCLUDED.status,
        onboarding_step = EXCLUDED.onboarding_step;

    RAISE NOTICE 'Seed complete: 1 test professional inserted/updated.';
END $$;
```

- [ ] **Step 7.2: Run tests**

```bash
composer test
```

Expected: all green (seed.sql is not executed in PHP tests — they use SQLite).

- [ ] **Step 7.3: Commit**

```bash
git add supabase/seed.sql
git commit -m "fix(B11/P3-16): rewrite seed.sql for standalone core.users (remove dead enterprise scenarios)"
```

---

## Task 8: Final verification

- [ ] **Step 8.1: Run full test suite**

```bash
composer test
```

Expected: all green.

- [ ] **Step 8.2: Confirm no stray references to removed symbols**

```bash
# Should return nothing (only AccountCapabilitySet.php itself would have had these)
grep -rn "ACTION_UNSELECT_PRODUCT\|unselect_product" app/ tests/ --include="*.php"

# Should return nothing (root-level exception is gone)  
grep -rn "App\\\\Exceptions\\\\NoRecipientEmailException[^;]" app/ tests/ --include="*.php" | grep -v "Gdpr"

# Should return nothing (method was deleted)
grep -rn "->store()" app/Http/Controllers/Api/Professional/SiteManagement/ --include="*.php"
```

- [ ] **Step 8.3: Summary**

All 9 items resolved:
- P3-07 ✅ (pre-existing comment, no action needed)
- P2-17 ✅ (config/database.php search_path)
- P3-17 ✅ (config.toml schemas)
- P3-10 ✅ (dead exception deleted)
- P3-06 ✅ (gallery store() stub removed)
- P2-31 ✅ (unselect_product purged)
- P2-13 ✅ (AccountType CHECK constraint migration)
- P3-08 ✅ (AccountCapabilitySet slimmed)
- P3-16 ✅ (seed.sql rewritten)
