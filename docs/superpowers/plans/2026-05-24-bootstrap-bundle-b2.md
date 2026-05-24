# Bootstrap Bundle B2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve audit findings #P0-07, #P1-01, #P2-32 (Bundle B2) by relaxing the `core.waitlist_signups` schema to match the email-only contract that two endpoints already assume, then extracting `ProfessionalBootstrapService` and lifting the disabled-account status check above `DB::transaction`.

**Architecture:**
- One new migration relaxes `name`/`phone`/`applicant_type`/`industry` to nullable and updates the CHECK constraints — fixes both the `/api/bootstrap` individual-waitlist divert AND the existing latent crash in `/api/public/waitlist`.
- `ProfessionalBootstrapService::bootstrap(string $uid, array $data): array` owns the transactional create-or-update path. Controller keeps waitlist-gate, individual-waitlist-divert, validation, and HTTP shaping.
- Disabled-account check moves **above** `DB::transaction` so the 403 short-circuits cleanly (no exception-through-closure dance). `EMAIL_ALREADY_REGISTERED` keeps its existing exception-based control flow inside the transaction.

**Tech Stack:** PHP 8.2 / Laravel 12 / PostgreSQL (Supabase) / Pest 4 / SQLite-in-memory test DB.

---

## File Structure

**Create:**
- `supabase/migrations/20260524120000_relax_waitlist_signups_constraints.sql` — relax NOT NULL + CHECK constraints on `core.waitlist_signups`
- `app/Services/Professional/ProfessionalBootstrapService.php` — transactional bootstrap service
- `tests/Feature/PublicSite/BootstrapDivertAndDisabledTest.php` — feature tests for the divert payload shape, disabled-account 403, and email-only public waitlist path (all three target the same schema fix so they live together)

**Modify:**
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php` — slim to gate + divert + delegate
- `tests/Pest.php` — repair stale `setupWaitlistTable()` helper (columns now match production names; still permissive)
- `tests/Feature/PublicSite/BootstrapWaitlistGateTest.php` — replace reflection on `hasExistingProfessional` with a behavioural test against the controller's gate
- `tests/Feature/PublicSite/PublicWaitlistControllerTest.php` — drop the NOT-NULL constraints from the test schema (to match the migration's new nullable columns) and add an email-only test

---

## Task 1: Branch + verify clean baseline

**Files:** none

- [ ] **Step 1: Create feature branch from development**

```bash
git checkout -b fix/b2-bootstrap-bundle
```

Expected: `Switched to a new branch 'fix/b2-bootstrap-bundle'`

- [ ] **Step 2: Baseline composer test (capture green state)**

```bash
composer test 2>&1 | tail -30
```

Expected: all green (or any pre-existing failures noted so post-implementation diff is interpretable).

---

## Task 2: Migration — relax `core.waitlist_signups` constraints

**Files:**
- Create: `supabase/migrations/20260524120000_relax_waitlist_signups_constraints.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Relax core.waitlist_signups to match the email-only signup contract.
-- The V2 baseline (20260526000000_baseline_standalone_user.sql) enforces
-- NOT NULL on name/phone/applicant_type/industry, but both endpoints that
-- write to this table (PublicWaitlistController and the BootstrapController
-- individual-waitlist divert) already pass NULLs for those columns. The
-- baseline schema reflects an earlier multi-field form that no longer
-- matches the frontend contract.
--
-- This migration:
--   1. Drops NOT NULL on name, phone, applicant_type, industry
--   2. Allows NULL in the applicant_type CHECK and adds 'individual'
--      (used by the BootstrapController individual-waitlist divert)
--   3. Allows NULL in the industry CHECK
--   4. Updates the _other_required CHECKs to allow NULL/NULL pairing

BEGIN;

-- 1. Drop NOT NULLs.
ALTER TABLE core.waitlist_signups
    ALTER COLUMN name DROP NOT NULL,
    ALTER COLUMN phone DROP NOT NULL,
    ALTER COLUMN applicant_type DROP NOT NULL,
    ALTER COLUMN industry DROP NOT NULL;

-- 2. applicant_type CHECK — allow NULL + add 'individual'.
ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_type_check;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_type_check CHECK (
        applicant_type IS NULL
        OR applicant_type IN ('influencer', 'professional', 'other', 'individual')
    );

-- 3. industry CHECK — allow NULL.
ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_industry_check;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_industry_check CHECK (
        industry IS NULL
        OR industry IN (
            'mens_grooming', 'womens_haircare', 'beauty_products',
            'vitamins_and_supplements', 'services_and_software', 'other'
        )
    );

-- 4. *_other_required CHECKs — allow NULL/NULL pairing alongside existing rules.
ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_type_other_required;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_type_other_required CHECK (
        applicant_type IS NULL
        OR (applicant_type = 'other' AND applicant_type_other IS NOT NULL AND btrim(applicant_type_other) <> '')
        OR (applicant_type <> 'other' AND applicant_type_other IS NULL)
    );

ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_industry_other_required;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_industry_other_required CHECK (
        industry IS NULL
        OR (industry = 'other' AND industry_other IS NOT NULL AND btrim(industry_other) <> '')
        OR (industry <> 'other' AND industry_other IS NULL)
    );

COMMIT;
```

- [ ] **Step 2: Stage-only — no DB push yet (push happens after review)**

No command. Migration sits in `supabase/migrations/` until Task 11.

---

## Task 3: Repair stale `setupWaitlistTable()` test helper

**Files:**
- Modify: `tests/Pest.php` (function `setupWaitlistTable` at line 337)

- [ ] **Step 1: Replace with production-aligned column list (still permissive)**

Replace the entire function with:

```php
/**
 * core.waitlist_signups for waitlist tests. Column list mirrors the production
 * baseline post-relaxation migration (20260524120000) — all columns nullable
 * here for SQLite permissiveness, but every column name matches.
 */
function setupWaitlistTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.waitlist_signups (
        id TEXT PRIMARY KEY,
        name TEXT NULL,
        email TEXT NULL,
        email_lc TEXT NULL UNIQUE,
        phone TEXT NULL,
        applicant_type TEXT NULL,
        applicant_type_other TEXT NULL,
        industry TEXT NULL,
        industry_other TEXT NULL,
        pilot_program_opt_in INTEGER NULL,
        number_of_team_members INTEGER NULL,
        consent_source TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        last_submitted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```

- [ ] **Step 2: Run any test that touches `setupWaitlistTable` to confirm no regression**

```bash
./vendor/bin/pest --filter=setupWaitlistTable 2>&1 | tail -10 || ./vendor/bin/pest --filter=waitlist 2>&1 | tail -20
```

Expected: PASS (or no tests match — the helper has no current consumers; the fix is preventive).

---

## Task 4: Failing test — email-only `/api/public/waitlist` accepts payload

**Files:**
- Modify: `tests/Feature/PublicSite/PublicWaitlistControllerTest.php`

- [ ] **Step 1: Relax the in-test schema (drop NOT NULLs) to match the new migration**

Replace `setupWaitlistSchema()` at the bottom of the file with:

```php
function setupWaitlistSchema(): void
{
    // Mirrors production schema after migration 20260524120000 (relaxed
    // constraints to match email-only signup contract). All columns nullable
    // here; in production NULLs are still enforced for *_other_required and
    // *_check via Postgres CHECK (not modelled in SQLite).
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.waitlist_signups (
        id TEXT PRIMARY KEY,
        name TEXT NULL,
        email TEXT NULL,
        email_lc TEXT NULL UNIQUE,
        phone TEXT NULL,
        applicant_type TEXT NULL,
        applicant_type_other TEXT NULL,
        industry TEXT NULL,
        industry_other TEXT NULL,
        pilot_program_opt_in INTEGER NULL DEFAULT 0,
        number_of_team_members INTEGER NULL,
        consent_source TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        last_submitted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```

- [ ] **Step 2: Add email-only test BEFORE the existing tests**

After the `beforeEach` and BEFORE `it('stores a waitlist submission with normalized fields', ...)`, insert:

```php
it('accepts an email-only waitlist submission (coming-soon landing)', function () {
    $response = $this->postJson('/api/public/waitlist', ['email' => 'emailonly@example.com']);

    $response->assertCreated()->assertJson(['ok' => true]);

    $row = DB::connection('pgsql')->table('core.waitlist_signups')
        ->where('email_lc', 'emailonly@example.com')->first();

    expect($row)->not->toBeNull();
    expect($row->name)->toBeNull();
    expect($row->phone)->toBeNull();
    expect($row->applicant_type)->toBeNull();
    expect($row->industry)->toBeNull();
});
```

- [ ] **Step 3: Run the new test — it should PASS now that NOT NULLs are relaxed (the prod-bug fix lives in the migration, the test simply locks it in)**

```bash
./vendor/bin/pest tests/Feature/PublicSite/PublicWaitlistControllerTest.php --filter="email-only" 2>&1 | tail -15
```

Expected: PASS.

---

## Task 5: Failing test — bootstrap divert writes the right payload shape

**Files:**
- Create: `tests/Feature/PublicSite/BootstrapDivertAndDisabledTest.php`

- [ ] **Step 1: Write the file**

```php
<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Models\Core\Professional\User;
use App\Services\Professional\ProfessionalBootstrapService;
use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupProfessionalsTable();
    setupWaitlistTable();
})->group('bootstrap-divert-and-disabled');

it('writes a clean divert row to core.waitlist_signups when individual_waitlist_enabled is on', function () {
    config(['partna.individual_waitlist_enabled' => true]);

    $controller = app(BootstrapController::class);
    $request = \App\Http\Requests\Api\BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'newdivert@example.com',
        'first_name' => 'Casey',
        'last_name' => 'Wright',
    ]);
    $request->attributes->set('supabase_uid', 'new-divert-uid');

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('INDIVIDUAL_WAITLIST');

    $row = DB::connection('pgsql')->table('core.waitlist_signups')
        ->where('email_lc', 'newdivert@example.com')->first();

    expect($row)->not->toBeNull();
    expect($row->email)->toBe('newdivert@example.com');
    expect($row->email_lc)->toBe('newdivert@example.com');
    expect($row->applicant_type)->toBe('individual');
    expect($row->consent_source)->toBe('individual_waitlist_divert');
    expect($row->name)->toBe('Casey Wright');
});

it('returns 403 ACCOUNT_DISABLED for disabled accounts (not 200 with empty body)', function () {
    config(['partna.individual_waitlist_enabled' => false]);
    config(['partna.waitlist.enabled' => false]);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-0000000000aa',
        'auth_user_id' => 'disabled-uid',
        'primary_email' => 'disabled@example.com',
        'handle' => 'disableduser',
        'handle_lc' => 'disableduser',
        'display_name' => 'Disabled User',
        'account_type' => 'individual',
        'status' => 'disabled',
    ]);

    $controller = app(BootstrapController::class);
    $request = \App\Http\Requests\Api\BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'disabled@example.com',
        'display_name' => 'Disabled User',
        'handle' => 'disableduser',
    ]);
    $request->attributes->set('supabase_uid', 'disabled-uid');

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('ACCOUNT_DISABLED');
});
```

- [ ] **Step 2: Run new tests — expect FAIL for both (divert currently writes `applicant_type='individual'` which violates the production CHECK but passes SQLite NOT NULL; disabled-account currently returns 200 `{}`)**

```bash
./vendor/bin/pest tests/Feature/PublicSite/BootstrapDivertAndDisabledTest.php 2>&1 | tail -30
```

Expected:
- Divert test: PASS (existing code already writes the right shape; this test locks it in for after refactor).
- Disabled test: FAIL with status 200 (proves the P1-01 bug).

---

## Task 6: Extract `ProfessionalBootstrapService`

**Files:**
- Create: `app/Services/Professional/ProfessionalBootstrapService.php`

- [ ] **Step 1: Write the service**

```php
<?php

namespace App\Services\Professional;

use App\Enums\AccountType;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Bootstrap a new or existing professional from a validated request. Runs the
// create-or-update under a single DB transaction. Disabled-status check is
// performed BEFORE the transaction so the 403 short-circuits cleanly.
class ProfessionalBootstrapService
{
    public function __construct(
        private readonly SiteProvisioningService $siteProvisioning,
        private readonly ProfessionalCacheService $cache,
    ) {}

    /**
     * Bootstrap or update a professional + their site.
     *
     * @param  array<string, mixed>  $data  Validated payload from BootstrapRequest
     * @return array{professional: User, site: Site, created: bool}
     *
     * @throws RuntimeException with one of: 'ACCOUNT_DISABLED', 'EMAIL_ALREADY_REGISTERED'.
     *   Other exceptions propagate.
     */
    public function bootstrap(string $uid, array $data): array
    {
        // Pre-transaction status check. Lifting this above DB::transaction means
        // an early 403 doesn't leak a JsonResponse through the closure return value
        // (the old bug returned the response object, which the outer success()
        // call then re-encoded into '{}').
        $existing = User::query()->where('auth_user_id', $uid)->first();
        if ($existing && in_array($existing->status, ['disabled', 'suspended', 'pending_deletion'], true)) {
            throw new RuntimeException('ACCOUNT_DISABLED');
        }

        return DB::transaction(function () use ($uid, $data, $existing) {
            $createdProfessional = false;
            $professional = $existing;

            if (! $professional) {
                $this->guardAgainstEmailReuseByDifferentAuthUser($data['primary_email'] ?? '', $uid);

                $createdProfessional = true;
                $professional = new User([
                    'handle' => $data['handle'],
                    'display_name' => $data['display_name'],
                    'bio' => null,
                    'country_code' => $data['country_code'] ?? null,
                    'timezone' => $data['timezone'] ?? null,
                    'account_type' => AccountType::Individual,
                    'status' => 'active',
                    'onboarding_step' => 0,
                    'phone' => $data['phone'] ?? null,
                    'primary_email' => $data['primary_email'],
                    'first_name' => $data['first_name'] ?? '',
                    'last_name' => $data['last_name'] ?? null,
                    'public_contact_number' => null,
                    'public_contact_email' => null,
                    'handle_lc' => $data['handle_lc'],
                ]);
                $professional->auth_user_id = $uid;
            } else {
                $professional->fill([
                    'handle' => $data['handle'],
                    'display_name' => $data['display_name'],
                    'primary_email' => $data['primary_email'],
                    'phone' => array_key_exists('phone', $data) ? $data['phone'] : $professional->phone,
                    'first_name' => $data['first_name'] ?? $professional->first_name,
                    'last_name' => $data['last_name'] ?? $professional->last_name,
                    'country_code' => $data['country_code'] ?? $professional->country_code,
                    'timezone' => $data['timezone'] ?? $professional->timezone,
                    'handle_lc' => $data['handle_lc'],
                ]);
            }

            $professional->save();

            $this->ensureSidestUpdatesSubscription($professional->primary_email);

            $site = Site::query()->where('professional_id', $professional->id)->first();
            if (! $site) {
                $base = $this->siteProvisioning->subdomainBaseFromHandle($data['handle']);
                $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
            }

            $this->cache->invalidateProfessional($professional);

            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);
            }

            return [
                'professional' => $professional->fresh(),
                'site' => $site->fresh(),
                'created' => $createdProfessional,
            ];
        });
    }

    private function guardAgainstEmailReuseByDifferentAuthUser(string $email, string $uid): void
    {
        $emailLc = strtolower(trim($email));
        if ($emailLc === '') {
            return;
        }

        $existingByEmail = User::query()
            ->whereRaw('lower(primary_email) = ?', [$emailLc])
            ->where('auth_user_id', '!=', $uid)
            ->exists();

        if ($existingByEmail) {
            throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
        }
    }

    private function ensureSidestUpdatesSubscription(?string $email): void
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if ($email === '') {
            return;
        }

        $listKey = 'sidest_updates';

        $existing = EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $listKey)
            ->where('email_lc', $email)
            ->first();

        if ($existing) {
            return;
        }

        $sub = new EmailSubscription([
            'professional_id' => null,
            'list_key' => $listKey,
            'email' => $email,
            'email_lc' => $email,
            'full_name' => null,
            'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
        ]);

        $sub->markSubscribed(['source' => 'bootstrap']);
        $sub->save();
    }

    private function createWelcomeNotification(User $professional): void
    {
        Notification::query()->firstOrCreate(
            [
                'professional_id' => $professional->id,
                'type' => 'Info',
                'title' => 'Welcome to Partna',
            ],
            [
                'body' => 'Your account is ready. Complete your profile and start building your professional page from your dashboard.',
                'cta_url' => null,
                'severity' => 'info',
                'starts_at' => now(),
                'ends_at' => null,
            ]
        );
    }
}
```

- [ ] **Step 2: Run a syntax check via tinker**

```bash
php -l app/Services/Professional/ProfessionalBootstrapService.php
```

Expected: `No syntax errors detected`.

---

## Task 7: Rebuild `BootstrapController` as a thin delegate

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/BootstrapController.php`

- [ ] **Step 1: Replace the whole class**

```php
<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Professional\User;
use App\Models\Core\Waitlist\WaitlistSignup;
use App\Services\Professional\ProfessionalBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Account signup/update. Keeps waitlist gating + individual-waitlist
// divert + HTTP shaping in the controller; delegates the create-or-update
// transaction to ProfessionalBootstrapService.
class BootstrapController extends ApiController
{
    public function __construct(
        private readonly ProfessionalBootstrapService $bootstrapService,
    ) {}

    public function bootstrap(BootstrapRequest $request): JsonResponse
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        if ($this->isWaitlistModeEnabled() && ! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'New account creation is currently waitlist-only. Please join the waitlist.',
                403,
                ['code' => 'WAITLIST_ONLY']
            );
        }

        // §28.14 — Individual waitlist diversion (CFG-1). Runs BEFORE validation
        // so a divert never produces a Professional row. Payload is intentionally
        // minimal: email + applicant_type='individual' + consent_source. Other
        // columns are nullable post-migration 20260524120000.
        if (
            (bool) config('partna.individual_waitlist_enabled', false)
            && ! $this->hasExistingProfessional($uid)
        ) {
            $emailLc = strtolower(trim((string) $request->input('primary_email', '')));
            if ($emailLc !== '') {
                $firstName = trim((string) $request->input('first_name', ''));
                $lastName = trim((string) $request->input('last_name', ''));
                $name = trim($firstName.' '.$lastName);

                WaitlistSignup::query()->updateOrCreate(
                    ['email_lc' => $emailLc],
                    [
                        'email' => $emailLc,
                        'name' => $name !== '' ? $name : null,
                        'applicant_type' => 'individual',
                        'consent_source' => 'individual_waitlist_divert',
                        'last_submitted_at' => now(),
                    ]
                );
            }

            return $this->error(
                'New individual signups are temporarily on a waitlist. We\'ll be in touch.',
                403,
                ['code' => 'INDIVIDUAL_WAITLIST']
            );
        }

        $data = $request->validated();

        try {
            $result = $this->bootstrapService->bootstrap($uid, $data);
        } catch (RuntimeException $e) {
            return $this->translateBootstrapException($e, $uid, $data['primary_email'] ?? null);
        }

        return $this->success([
            'professional' => new ProfessionalDashboardResource($result['professional']),
            'site' => $result['site'],
        ]);
    }

    private function translateBootstrapException(RuntimeException $e, string $uid, ?string $email): JsonResponse
    {
        if ($e->getMessage() === 'ACCOUNT_DISABLED') {
            return $this->error(
                'Account is disabled. Contact support.',
                403,
                ['code' => 'ACCOUNT_DISABLED']
            );
        }

        if ($e->getMessage() === 'EMAIL_ALREADY_REGISTERED') {
            Log::info('Bootstrap rejected: email already registered to another auth user', [
                'uid' => $uid,
                'email' => $email,
            ]);

            return $this->error(
                'This email is already associated with a different account. Sign in with your original method, or contact support to link accounts.',
                409,
                ['code' => 'EMAIL_ALREADY_REGISTERED']
            );
        }

        Log::error('Bootstrap transaction failed', [
            'error' => $e->getMessage(),
            'uid' => $uid,
        ]);
        throw $e;
    }

    private function isWaitlistModeEnabled(): bool
    {
        return (bool) config('partna.waitlist.enabled', false);
    }

    private function hasExistingProfessional(string $uid): bool
    {
        return User::query()
            ->where('auth_user_id', $uid)
            ->exists();
    }
}
```

- [ ] **Step 2: Run all bootstrap-related tests**

```bash
./vendor/bin/pest --filter=bootstrap 2>&1 | tail -40
```

Expected: all PASS (including the previously-failing disabled-account test from Task 5).

---

## Task 8: Update `BootstrapWaitlistGateTest` — drop reflection on private method

**Files:**
- Modify: `tests/Feature/PublicSite/BootstrapWaitlistGateTest.php`

- [ ] **Step 1: Replace the reflection-based test with a behavioural one**

Replace the `it('detects existing professionals by supabase auth user id', ...)` block with:

```php
it('does not gate existing professionals when waitlist mode is enabled', function () {
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-000000000001',
        'auth_user_id' => 'existing-user-uid',
        'primary_email' => 'existing@example.com',
        'handle' => 'existing',
        'handle_lc' => 'existing',
        'display_name' => 'Existing User',
        'status' => 'active',
        'account_type' => 'individual',
    ]);

    $controller = app(BootstrapController::class);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'existing@example.com',
        'display_name' => 'Existing User',
        'handle' => 'existing',
    ]);
    $request->attributes->set('supabase_uid', 'existing-user-uid');

    $response = $controller->bootstrap($request);

    // Existing professionals bypass the waitlist gate — they hit validation/service.
    // The gate's 403 with WAITLIST_ONLY must NOT fire for them.
    expect($response->getStatusCode())->not->toBe(403);
});
```

Also update the controller construction in `it('blocks bootstrap for new users when waitlist mode is enabled', ...)` from:

```php
$controller = new BootstrapController(new SiteProvisioningService);
```

to:

```php
$controller = app(BootstrapController::class);
```

(The constructor signature changed from `SiteProvisioningService` to `ProfessionalBootstrapService`; resolving via the container avoids hardcoding the dep.)

- [ ] **Step 2: Drop the now-unused `SiteProvisioningService` import at the top of the file if appropriate.**

- [ ] **Step 3: Run the gate test**

```bash
./vendor/bin/pest tests/Feature/PublicSite/BootstrapWaitlistGateTest.php 2>&1 | tail -15
```

Expected: PASS.

---

## Task 9: Full test sweep + commit

**Files:** none

- [ ] **Step 1: Run full Pest suite**

```bash
composer test 2>&1 | tail -40
```

Expected: green (same pass count as Task 1 baseline, plus the 3 new tests).

- [ ] **Step 2: Run `pint` for style**

```bash
./vendor/bin/pint app/Services/Professional/ProfessionalBootstrapService.php app/Http/Controllers/Api/PublicSite/BootstrapController.php tests/Feature/PublicSite/BootstrapDivertAndDisabledTest.php tests/Feature/PublicSite/BootstrapWaitlistGateTest.php tests/Feature/PublicSite/PublicWaitlistControllerTest.php tests/Pest.php
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
fix(B2): bootstrap bundle — relax waitlist constraints + extract service + fix disabled-account 403

Resolves audit findings #P0-07, #P1-01, #P2-32.

- Migration relaxes core.waitlist_signups NOT NULLs and CHECK constraints
  on name/phone/applicant_type/industry. Fixes both /api/bootstrap divert
  (which writes applicant_type='individual') and /api/public/waitlist
  (which already wrote nulls for the email-only path — latent crash that
  hadn't fired because the flag was off).
- Extract ProfessionalBootstrapService. Controller becomes a thin delegate
  for the bootstrap path; keeps waitlist gate + individual-waitlist divert
  + HTTP shaping.
- Lift disabled-account status check above DB::transaction. The previous
  `return $this->error(...)` inside the closure returned a JsonResponse
  *object* through the transaction, which the outer success() then
  re-encoded into '{}' — frontend never saw the 403.
- Add ACCOUNT_DISABLED error code to the disabled-account response for
  frontend disambiguation.
- New tests:
  - PublicWaitlistControllerTest: email-only payload accepted
  - BootstrapDivertAndDisabledTest: divert payload shape + 403 ACCOUNT_DISABLED
- Repair stale setupWaitlistTable() helper in tests/Pest.php — column
  names now match production.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Opus adversarial review (per bundle prompt)

**Files:** none

- [ ] **Step 1: Dispatch opus subagent for review**

Use the Agent tool, model=opus, subagent_type=general-purpose. Prompt:

> Review the diff on the current branch (`fix/b2-bootstrap-bundle`) against audit findings #P0-07, #P1-01, #P2-32 in `audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md` (lines 122–141, 433–436) and the bundle in lines 580–611.
>
> Be paranoid. Don't validate confidently — try to break it.
>
> 1. Does each fix match its finding?
> 2. Regressions in: bootstrap signup flow, waitlist divert, disabled-account 403 path, GDPR applicant_type constraint, PublicWaitlistController.
> 3. Edge cases the implementor likely missed (transaction-closure-returns-response object, partial-state on mid-transaction failure, race between professional create and email-uniqueness check).
> 4. Migration safety: lock contention on `ALTER TABLE` (Postgres `ACCESS EXCLUSIVE`)? Reversibility? Idempotency?
> 5. Run `composer test`. Report the result.
>
> Report under 600 words.

- [ ] **Step 2: Address any P0/P1 findings from the review**

If review surfaces issues, fix and commit as a follow-up commit on the same branch. Re-run `composer test` after each fix.

---

## Task 11: Push to GitHub + open PR + push to Supabase dev

**Files:** none

- [ ] **Step 1: Push branch**

```bash
git push -u origin fix/b2-bootstrap-bundle
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "fix(B2): bootstrap bundle — waitlist constraints + service extraction + disabled-account 403" --body "$(cat <<'EOF'
## Summary
- Resolves audit findings #P0-07 (waitlist CHECK crash), #P1-01 (disabled-account 200), #P2-32 (Bootstrap god method)
- Migration relaxes `core.waitlist_signups` NOT NULLs + CHECKs to match the email-only contract that both `/api/bootstrap` divert AND `/api/public/waitlist` already assume — fixes a latent 500 in the public waitlist endpoint too
- Extracts `ProfessionalBootstrapService`; controller becomes thin delegate
- Lifts disabled-status check above `DB::transaction` (was returning a JsonResponse through the closure, which the outer `success()` re-encoded as `{}`)

## Test plan
- [ ] `composer test` green (PublicWaitlistControllerTest + BootstrapDivertAndDisabledTest added)
- [ ] After merge: `supabase link --project-ref glncumufgaqcmqhzwrxm` then `supabase db push --dry-run` then `supabase db push` to dev
- [ ] Manual smoke against dev: `POST /api/public/waitlist` with `{email:"smoke@test.com"}` returns 201, row visible in core.waitlist_signups with all-null optional columns
- [ ] Manual smoke: flip `SIDEST_INDIVIDUAL_WAITLIST_ENABLED=true` in dev, hit `/api/bootstrap` with a fresh JWT — expect 403 INDIVIDUAL_WAITLIST and a clean divert row
- [ ] Manual smoke: bootstrap with a known disabled user → 403 ACCOUNT_DISABLED (not 200 `{}`)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Push migration to Supabase dev (after PR merged)**

This waits for Josh's merge into `development`. After merge:

```bash
# Josh runs this interactively (uses `!` prefix in chat for interactive auth):
supabase link --project-ref glncumufgaqcmqhzwrxm
```

Then I run:

```bash
supabase db push --dry-run
```

Confirm output shows only the new migration. Then:

```bash
supabase db push
```

- [ ] **Step 4: Smoke-check dev**

```bash
cloud env:logs partna development --minutes 5 | grep -iE "waitlist|bootstrap" || echo "no recent waitlist/bootstrap traffic"
```

Use `curl` or browser to hit `/api/public/waitlist` with email-only payload; verify 201 + clean DB row.

---

## Self-Review

- **Spec coverage:** P0-07 → Task 2 (migration) + Task 5 (divert test) + Task 7 (controller divert payload). P1-01 → Task 6 (lift status check above transaction) + Task 7 (translateBootstrapException maps ACCOUNT_DISABLED → 403) + Task 5 (disabled-account test). P2-32 → Task 6 (service) + Task 7 (controller thin delegate). ✓
- **Placeholders:** none — every step has exact code or exact command.
- **Type consistency:** `ProfessionalBootstrapService::bootstrap(string $uid, array $data): array` returns `{professional, site, created}`; controller consumes `$result['professional']` and `$result['site']`. ✓
- **Public waitlist scope creep justified:** schema fix benefits both endpoints simultaneously; only addition is one feature test (Task 4). No `PublicWaitlistController` code change.
- **SQLite limitations acknowledged:** CHECK constraints unenforced in test DB; NOT NULL IS enforced, which is what we need to lock in the email-only test.
- **Migration safety:** `ALTER TABLE ... ALTER COLUMN ... DROP NOT NULL` takes `ACCESS EXCLUSIVE` briefly but on a near-empty waitlist table is sub-millisecond. CHECK swaps are atomic via DROP/ADD. Wrapped in transaction.
