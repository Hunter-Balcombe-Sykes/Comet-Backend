# Pre-Account Sites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Invert signup to site-first — build a real site from a typed Instagram handle (partna) or Google Business Profile (business) under a provisional account-less user, claimed later via Supabase email OTP first-come; the same mechanism powers staff/ManyChat marketing builds.

**Architecture:** Approach A (provisional users): a build creates a real `core.users` row with `auth_user_id = NULL`, `primary_email = NULL`, `status = 'unclaimed'` plus an ordinary `site.sites` row, so the entire public render path works unchanged. A permanent `core.pre_account_builds` audit row (1:1 with the user) tracks source + build state + expiry. Generators reuse the existing Instagram scrape/mirror machinery and the existing Google Places + IdentitySync machinery via `IntegrationConnection` rows — an unclaimed site is content-identical to a connected user's site.

**Tech Stack:** Laravel 12 / PHP 8.2, Supabase Postgres (raw SQL migrations), Pest 4 (SQLite in-memory), Redis queues (scraping lane), Cloudflare KV/cache via existing jobs.

**Spec:** `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md` (approved; decisions settled — do not re-litigate).

## Global Constraints

- Schema changes = raw SQL in `supabase/migrations/` ONLY (composer guard rejects Laravel migrations). One migration file for this feature.
- Migrations do NOT auto-run on deploy — apply to dev Supabase (`glncumufgaqcmqhzwrxm`) via `supabase db push` with Josh in the loop. NEVER the prod ref.
- `SyncSubdomainToKvJob` stays the ONLY KV writer. No other class may call `CloudflareKvService::put()/bulkPut()/delete()`.
- Authorization via Policies + `authorizeForUser()` (never `authorize()`, never inline 403 in controllers). New policies registered in `AppServiceProvider::boot()`.
- 404 (not 403) on public routes for missing/inaccessible resources.
- Jobs: `$tries` + `$timeout` properties and `$backoff` property or `backoff()` method (JobHygienePolicyTest). Dispatch with `->afterCommit()` when inside a transaction — NEVER a typed `public bool $afterCommit` property (trait-conflict fatal).
- Resource classes for API responses; Form Requests for validation.
- Tenancy FKs (`user_id`, `built_by_staff_id`) are set via `->relation()->associate()` or direct property assignment — never `$fillable` (SEC-1 rule).
- Tests run on SQLite; Postgres CHECK constraints / NOT NULLs / partial indexes / DB triggers are NOT enforced there. Every constraint-bound write must be verified against the migration DDL by reading it, and the schema-drift snapshot (`scripts/refresh-schema-snapshot.php`) refreshed after the migration lands.
- New test/service/job directories must be wired into `scripts/audit/audit.sh` `codebase_chunks()` + a lens scope-group (AuditPipelineIntegrityTest enforces).
- Josh commits and pushes — no task pushes to a remote. Commit locally per task.
- Branch: `feat/pre-account-sites-2026-07-18` off `origin/development`.

## Verified premises & corrections to the spec (read before implementing)

These were verified against the live dev DB (`glncumufgaqcmqhzwrxm`) and current code on 2026-07-18. Where the spec text differs, THIS section wins:

1. **`users_status_check` EXISTS** (spec §3 says it doesn't): live CHECK is `status IN ('active','suspended','disabled','pending_deletion')`. The migration must widen it to add `'unclaimed'`.
2. **Both unique indexes are already partial on `deleted_at IS NULL`**: `users_auth_user_id_unique` and `users_email_unique ON (lower(primary_email))`. New predicates must COMBINE: `WHERE x IS NOT NULL AND deleted_at IS NULL`.
3. **`site.public_site_payload` view filters `p.status = 'active'`** in its WHERE clause. Must be re-created with `p.status IN ('active','unclaimed')` or published unclaimed sites 404 at the view layer (SQLite tests cannot catch this).
4. **`PublicSiteResolver` gates `status = 'active'`** (`app/Services/PublicSite/PublicSiteResolver.php:27`) — must widen.
5. **`SyncSubdomainToKvJob` retires any non-active owner** (`!$pro->isActive()` gate) — an unclaimed user's KV entry would be evicted. Must learn `'unclaimed'` is routable, with TTL.
6. **Instagram scraped media never enters `site_media`** — `InstagramConnectJob` mirrors bytes to R2 under `platforms/instagram/{connection created_at ts}/` and stores URLs in `integration_connections.payload`; `ContentSelectionService` + the public integrations endpoint render from the payload. The spec's "rehosted into site_media pools" phrase is wrong about the destination; the plan reuses the connection machinery (extraction in Task 8), which is what "existing platform machinery" actually is.
7. **`settings.google_business_profile` no longer exists** — promoted to the `site.workplaces` table (1:1 on `site_id`). Google Places integration already exists: `GoogleBusinessService::fetchPlaceDetails()` (server key in `config/services.php` → `services.google_maps.server_api_key`) + `IdentitySync::applyFromGooglePayload()` folds a payload into the workplace row. No new Places config needed.
8. **`SiteProvisioningService::tryCreateSite` hardcodes `is_published => true`** — needs a publish parameter (Task 6).
9. **`core.users` NOT NULLs**: `first_name`, `display_name`, `handle`, `handle_lc` — provisional-user creation must fill all four (spec omits `first_name`).
10. **Handle collision machinery is a private method of `BootstrapRequest`** (`generateHandleFromDisplayName`) — extracted to a shared service in Task 5.
11. **Design kit auto-creation is a Postgres trigger** (`trg_create_empty_design_kit`) — works in dev/prod, does NOT fire on SQLite; tests needing a design-kit row create it explicitly.
12. **Staff table is `core.partna_staff`**; `auth_user_id` FK → `auth.users ON DELETE CASCADE` (stays valid when nullable).
13. **Scheduling lives in `routes/console.php`** (no `app/Console/Kernel.php`).
14. **`User::routeNotificationForMail(): string`** throws `TypeError` on a null email (reachable via `NotifyReportedUserJob`) — fixed in Task 3.

## Flagged decisions (Josh sign-off at plan review — small deviations the verified code forced)

- **F1 — `source_name` request field for `google_business` builds.** A GBP `place_id` is opaque; the subdomain/handle/display-name seed can't be derived from it at request time (the scrape happens async in the job). The build endpoints accept `source_name` (`required_if:source_type,google_business`) — the frontend Places picker and staff/ManyChat both know the business name. Instagram builds ignore it.
- **F2 — `created_ip_hash` column added to `core.pre_account_builds`.** Spec §8 requires "a cap on outstanding unclaimed builds per IP" but the §3 DDL has no IP column. Added as `text NULL` (sha256 of CF-Connecting-IP; null for staff builds).
- **F3 — failed-build dedupe re-serves by retrying.** A dedupe hit on a `failed` live build resets it to `pending` and re-dispatches the job (instead of serving a dead failed build for up to 24h until prune).
- **F4 — no literal "link block" rows are created.** IG/GBP content renders via the connection payload + workplace row exactly as for existing connected users (that's how the platform renders these today). Creating `site.blocks` link rows would be a new write path not in any existing seam.
- **F5 — waitlist gate at the build endpoint is a plain 403** (`WAITLIST_ONLY`) when `partna.waitlist.enabled` is true. The invite-token bypass can't work there (no email exists at build time); invite flow effectively retires with the bootstrap create branch. Frontend contract section notes this.
- **F6 — bootstrap create branch retired at the controller** (410 `SIGNUP_MOVED` when the caller has no `core.users` row); `UserBootstrapService`'s create branch stays in place but becomes unreachable over HTTP (it shares machinery the claim flow reuses; deleting it would churn dozens of green tests for no behavior change).

## File structure (new / modified)

```
supabase/migrations/20260718200000_pre_account_sites.sql          (new)
config/partna.php                                                  (+ pre_account block)
app/Models/Core/User/PreAccountBuild.php                           (new)
app/Models/Core/User/User.php                                      (+ isUnclaimed(), preAccountBuild())
app/Policies/PreAccountBuildPolicy.php                             (new)
app/Services/User/HandleAllocator.php                              (new — extracted)
app/Services/User/EmailReuseGuard.php                              (new — extracted)
app/Services/User/SignupSideEffects.php                            (new — extracted)
app/Services/User/SiteProvisioningService.php                      (+ $published param)
app/Services/User/UserBootstrapService.php                         (delegates to extractions)
app/Http/Requests/Api/BootstrapRequest.php                         (delegates to HandleAllocator)
app/Services/PreAccount/PreAccountBuildService.php                 (new)
app/Services/PreAccount/ClaimSiteService.php                       (new)
app/Services/PreAccount/PreAccountBuildException.php               (new)
app/Services/PreAccount/SourceGenerationException.php              (new)
app/Services/PreAccount/SourceGeneratorRegistry.php                (new)
app/Services/PreAccount/Generators/SiteSourceGenerator.php         (new — interface)
app/Services/PreAccount/Generators/InstagramSourceGenerator.php    (new)
app/Services/PreAccount/Generators/GoogleBusinessSourceGenerator.php (new)
app/Services/Platforms/InstagramConnectionSeeder.php               (new — extracted from InstagramConnectJob)
app/Jobs/Platforms/InstagramConnectJob.php                         (delegates to seeder)
app/Jobs/PreAccount/GeneratePreAccountSiteJob.php                  (new)
app/Jobs/Cloudflare/SyncSubdomainToKvJob.php                       (unclaimed routability + TTL)
app/Services/PublicSite/PublicSiteResolver.php                     (status widening)
app/Services/User/AccountDeletionService.php                       (purgeMediaArtifacts → public)
app/Console/Commands/PruneExpiredPreAccountBuilds.php              (new)
routes/console.php                                                  (+ schedule entry)
app/Http/Controllers/Api/PublicSite/PreAccountBuildController.php  (new)
app/Http/Controllers/Api/PublicSite/ClaimController.php            (new)
app/Http/Controllers/Api/PublicSite/BootstrapController.php        (410 create-branch retirement)
app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php (new)
app/Http/Requests/Api/PublicSite/CreatePreAccountBuildRequest.php  (new)
app/Http/Requests/Api/PublicSite/ClaimSiteRequest.php              (new)
app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php (new)
app/Http/Resources/PreAccountBuildStatusResource.php               (new)
app/Http/Resources/UserStaffResource.php                           (+ pre_account_build block)
app/Providers/AppServiceProvider.php                               (policy + 2 rate limiters)
routes/api/publicSite.php, routes/api.php, routes/api/staff.php    (routes)
database/factories/PreAccountBuildFactory.php                      (new)
tests/Pest.php                                                     (+ setupPreAccountBuildsTable())
tests/Feature/PreAccount/…                                         (new test dir)
scripts/audit/audit.sh                                             (codebase_chunks wiring)
docs/api.md, AI_CONTEXT.md                                         (touched sections only)
```

---

# Phase 1 — Schema + gating groundwork

### Task 1: Migration — nullability, status CHECK, view, `core.pre_account_builds`

**Files:**
- Create: `supabase/migrations/20260718200000_pre_account_sites.sql`
- Modify: `scripts/schema-drift-baseline.json` / `scripts/schema-snapshot.json` (via refresh script, after push)

**Interfaces:**
- Produces: nullable `core.users.auth_user_id` / `primary_email`; `'unclaimed'` in `users_status_check`; table `core.pre_account_builds`; widened `site.public_site_payload` view. Everything later depends on this DDL.

- [ ] **Step 1: Write the migration**

Find the current canonical `site.public_site_payload` definition first: `grep -rn "CREATE OR REPLACE VIEW site.public_site_payload" supabase/migrations/` and copy the LATEST full definition into the migration below where indicated, changing ONLY the final WHERE clause from `p.status = 'active'` to `p.status IN ('active', 'unclaimed')`. Also mirror the GRANT/RLS treatment used by `supabase/migrations/20260701150000_create_workplaces.sql` for the new table (same `app_backend` grant style).

```sql
-- Pre-Account Sites (spec 2026-07-18): provisional account-less users + build audit table.
-- Owners are account-less until claim: auth_user_id/primary_email become nullable,
-- status gains 'unclaimed', and the public payload view treats unclaimed owners as
-- renderable (publish state is a separate knob).

-- 1. Nullability — provisional users have no auth user and no email yet.
ALTER TABLE core.users ALTER COLUMN auth_user_id DROP NOT NULL;
ALTER TABLE core.users ALTER COLUMN primary_email DROP NOT NULL;

-- 2. Rebuild the partial unique indexes: combine the NEW null-exclusion with the
--    EXISTING soft-delete predicate (verified live: both currently WHERE deleted_at IS NULL).
DROP INDEX IF EXISTS core.users_auth_user_id_unique;
CREATE UNIQUE INDEX users_auth_user_id_unique ON core.users (auth_user_id)
  WHERE auth_user_id IS NOT NULL AND deleted_at IS NULL;

DROP INDEX IF EXISTS core.users_email_unique;
CREATE UNIQUE INDEX users_email_unique ON core.users (lower(primary_email))
  WHERE primary_email IS NOT NULL AND deleted_at IS NULL;

-- 3. Widen the status vocabulary (verified live: CHECK exists in the baseline).
ALTER TABLE core.users DROP CONSTRAINT users_status_check;
ALTER TABLE core.users ADD CONSTRAINT users_status_check
  CHECK (status IN ('active', 'suspended', 'disabled', 'pending_deletion', 'unclaimed'));

-- 4. Build audit table — 1:1 with the provisional user, survives claim (permanent
--    origin record, NOT a ledger of ongoing source interactions).
CREATE TABLE core.pre_account_builds (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           uuid NOT NULL UNIQUE REFERENCES core.users(id) ON DELETE CASCADE,
  source_type       text NOT NULL CHECK (source_type IN ('instagram', 'google_business')),
  source_ref        text NOT NULL,
  source_ref_lc     text NOT NULL,
  built_via         text NOT NULL CHECK (built_via IN ('signup', 'staff')),
  built_by_staff_id uuid NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
  build_state       text NOT NULL DEFAULT 'pending'
                    CHECK (build_state IN ('pending', 'building', 'ready', 'failed')),
  failure_code      text NULL,
  created_ip_hash   text NULL,          -- sha256(CF-Connecting-IP); NULL for staff builds (F2)
  expires_at        timestamptz NOT NULL,
  claimed_at        timestamptz NULL,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);

-- One LIVE unclaimed build per source: retyping the same handle re-serves the
-- existing build instead of stacking squatters / re-scraping.
CREATE UNIQUE INDEX pre_account_builds_live_source_unique
  ON core.pre_account_builds (source_type, source_ref_lc)
  WHERE claimed_at IS NULL;

CREATE INDEX pre_account_builds_expiry_idx
  ON core.pre_account_builds (expires_at) WHERE claimed_at IS NULL;

-- Outstanding-per-IP abuse cap lookup (F2).
CREATE INDEX pre_account_builds_ip_idx
  ON core.pre_account_builds (created_ip_hash) WHERE claimed_at IS NULL AND created_ip_hash IS NOT NULL;

-- <GRANT/RLS block copied from the 20260701150000_create_workplaces.sql pattern>

-- 5. Public payload view: unclaimed owners render (publish state is the visibility knob).
-- <FULL CREATE OR REPLACE VIEW site.public_site_payload copied from the latest
--  migration defining it, with ONLY the WHERE clause changed to:
--  WHERE s.is_published = true AND p.status IN ('active', 'unclaimed') AND p.deleted_at IS NULL>
```

- [ ] **Step 2: Guard check** — Run: `composer test -- --filter=nothing 2>/dev/null || true` is NOT the check; run the migration guard explicitly: `composer run guard:no-laravel-migrations`. Expected: passes (file is raw SQL under `supabase/migrations/`).

- [ ] **Step 3: Apply to dev Supabase (Josh in the loop).** Ask Josh to run `! supabase link --project-ref glncumufgaqcmqhzwrxm`, then run `supabase db push --dry-run`, show him the output, then `supabase db push`. If the CLI reports drift (known: deleted-but-applied migrations), reconcile per the drift runbook (`supabase migration repair` / `--include-all`) — ask Josh before any repair.

- [ ] **Step 4: Verify live DDL** via Supabase MCP `execute_sql` against `glncumufgaqcmqhzwrxm` (NEVER prod):
```sql
SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
WHERE conrelid IN ('core.users'::regclass, 'core.pre_account_builds'::regclass);
SELECT indexname, indexdef FROM pg_indexes WHERE schemaname='core' AND tablename IN ('users','pre_account_builds');
```
Expected: `users_status_check` includes `'unclaimed'`; both users indexes carry the combined predicates; `pre_account_builds_live_source_unique` present; view definition (`SELECT pg_get_viewdef('site.public_site_payload'::regclass)`) contains `('active', 'unclaimed')`.

- [ ] **Step 5: Refresh the schema-drift snapshot**

Run: `php scripts/refresh-schema-snapshot.php` then `composer test` (full suite — the drift gate consumes the snapshot). Expected: suite green.

- [ ] **Step 6: Commit** — `git add supabase/migrations/20260718200000_pre_account_sites.sql scripts/schema-snapshot.json scripts/schema-drift-baseline.json && git commit -m "feat(pre-account): migration — provisional users, pre_account_builds, view widening"`

### Task 2: `PreAccountBuild` model, SQLite schema, User helpers, policy, factory

**Files:**
- Create: `app/Models/Core/User/PreAccountBuild.php`, `app/Policies/PreAccountBuildPolicy.php`, `database/factories/PreAccountBuildFactory.php`
- Modify: `app/Models/Core/User/User.php`, `tests/Pest.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Models/PreAccountBuildTest.php`

**Interfaces:**
- Produces: `PreAccountBuild` (constants `STATE_PENDING|STATE_BUILDING|STATE_READY|STATE_FAILED`, `FAILURE_SOURCE_NOT_FOUND = 'source_not_found'`, `FAILURE_SCRAPE_FAILED = 'scrape_failed'`, `VIA_SIGNUP='signup'`, `VIA_STAFF='staff'`; relations `user()`, `builtByStaff()`; scope `live()`), `User::isUnclaimed(): bool`, `User::preAccountBuild(): HasOne`, `setupPreAccountBuildsTable()` test helper.

- [ ] **Step 1: Write the failing test** (`tests/Unit/Models/PreAccountBuildTest.php`)

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
});

it('links 1:1 to its provisional user and scopes live builds', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);

    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => 'JaneDoe',
        'source_ref_lc' => 'janedoe',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->save();

    expect($user->fresh()->preAccountBuild->id)->toBe($build->id)
        ->and($user->fresh()->isUnclaimed())->toBeTrue()
        ->and(PreAccountBuild::live()->count())->toBe(1);

    $build->update(['claimed_at' => now()]);
    expect(PreAccountBuild::live()->count())->toBe(0);
});

it('does not mass-assign tenancy FKs', function () {
    expect((new PreAccountBuild)->isFillable('user_id'))->toBeFalse()
        ->and((new PreAccountBuild)->isFillable('built_by_staff_id'))->toBeFalse();
});
```

- [ ] **Step 2: Run** `./vendor/bin/pest tests/Unit/Models/PreAccountBuildTest.php` — Expected: FAIL (class/table missing).

- [ ] **Step 3: Implement**

`tests/Pest.php` — add beside `setupUsersTable()` (same permissive pattern; all-nullable except load-bearing defaults):

```php
function setupPreAccountBuildsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.pre_account_builds (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        source_type TEXT NULL,
        source_ref TEXT NULL,
        source_ref_lc TEXT NULL,
        built_via TEXT NULL,
        built_by_staff_id TEXT NULL,
        build_state TEXT NULL DEFAULT \'pending\',
        failure_code TEXT NULL,
        created_ip_hash TEXT NULL,
        expires_at TEXT NULL,
        claimed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```

`app/Models/Core/User/PreAccountBuild.php`:

```php
<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Permanent origin record for a pre-account (site-first) build. 1:1 with the
// provisional user; survives claim. NOT a ledger of ongoing source interactions —
// post-claim refreshes belong to platform_connections.
class PreAccountBuild extends BaseModel
{
    use HasFactory, HasUuids;

    public const STATE_PENDING = 'pending';
    public const STATE_BUILDING = 'building';
    public const STATE_READY = 'ready';
    public const STATE_FAILED = 'failed';

    public const FAILURE_SOURCE_NOT_FOUND = 'source_not_found';
    public const FAILURE_SCRAPE_FAILED = 'scrape_failed';

    public const VIA_SIGNUP = 'signup';
    public const VIA_STAFF = 'staff';

    protected $table = 'core.pre_account_builds';

    // user_id / built_by_staff_id deliberately NOT fillable — set via associate().
    protected $fillable = [
        'source_type', 'source_ref', 'source_ref_lc', 'built_via',
        'build_state', 'failure_code', 'created_ip_hash', 'expires_at', 'claimed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function builtByStaff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'built_by_staff_id');
    }

    /** Live = not yet claimed (the partial-unique-index predicate). */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('claimed_at');
    }
}
```

(Verify the `PartnaStaff` model's actual namespace with `grep -rn "class PartnaStaff" app/Models/` and use it.)

`app/Models/Core/User/User.php` — beside `isActive()` (line ~128):

```php
    /** Canonical 'unclaimed' predicate (pre-account build; no auth user yet). */
    public function isUnclaimed(): bool
    {
        return mb_strtolower(trim((string) $this->status)) === 'unclaimed';
    }

    public function preAccountBuild(): HasOne
    {
        return $this->hasOne(PreAccountBuild::class, 'user_id');
    }
```

`app/Policies/PreAccountBuildPolicy.php` (staff-only surface; public endpoints are unauthenticated and never hit the policy):

```php
<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;

class PreAccountBuildPolicy extends BasePolicy
{
    /** Any staff member may trigger a marketing build (route already enforces AAL2). */
    public function staffCreate(PartnaStaff $actor): bool
    {
        return true;
    }
}
```

Register in `AppServiceProvider::boot()` beside the existing `Gate::policy` lines:

```php
Gate::policy(\App\Models\Core\User\PreAccountBuild::class, \App\Policies\PreAccountBuildPolicy::class);
```

`database/factories/PreAccountBuildFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Database\Eloquent\Factories\Factory;

class PreAccountBuildFactory extends Factory
{
    protected $model = PreAccountBuild::class;

    public function definition(): array
    {
        $ref = $this->faker->userName();

        return [
            'source_type' => 'instagram',
            'source_ref' => $ref,
            'source_ref_lc' => mb_strtolower($ref),
            'built_via' => PreAccountBuild::VIA_SIGNUP,
            'build_state' => PreAccountBuild::STATE_PENDING,
            'expires_at' => now()->addDays(30),
        ];
    }
}
```

(Check `database/factories/` for the namespace convention of other Core models — mirror it, including `newFactory()` on the model if the existing pattern requires it for namespaced models.)

- [ ] **Step 4: Run** `./vendor/bin/pest tests/Unit/Models/PreAccountBuildTest.php` — Expected: PASS. Then `./vendor/bin/pest tests/Feature/Security/PolicyCoverageTest.php` — Expected: PASS (policy registered).

- [ ] **Step 5: Commit** — `git commit -m "feat(pre-account): PreAccountBuild model + policy + test schema + User helpers"`

### Task 3: Null-email safety

**Files:**
- Modify: `app/Models/Core/User/User.php:105-109`
- Test: `tests/Unit/Models/UserNullEmailTest.php`

**Interfaces:**
- Produces: `User::routeNotificationForMail(): ?string` — null-safe mail routing (Laravel's mail channel skips a null route).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\User\User;

beforeEach(fn () => setupUsersTable());

it('routes mail as null for an email-less provisional user instead of throwing', function () {
    $user = User::factory()->create(['primary_email' => null, 'status' => 'unclaimed', 'auth_user_id' => null]);

    expect($user->routeNotificationForMail())->toBeNull();
});
```

- [ ] **Step 2: Run** — Expected: FAIL with `TypeError: ...routeNotificationForMail(): Return value must be of type string, null returned`.

- [ ] **Step 3: Implement** — change the return type and PHPDoc:

```php
    /**
     * Mail-channel routing. Nullable: provisional (unclaimed) users have no email
     * until claim — returning null makes the mail channel skip them instead of
     * fataling (TypeError) inside any queued notification.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->primary_email;
    }
```

- [ ] **Step 4: Run the test (PASS), then the full suite** `composer test` — Expected: green (behavior for non-null emails unchanged).

- [ ] **Step 5: Commit** — `git commit -m "fix(pre-account): null-safe mail routing for email-less provisional users"`

### Task 4: Status-machine gating sweep

**Files:**
- Modify: `app/Services/PublicSite/PublicSiteResolver.php:26-28`
- Test: `tests/Feature/PreAccount/UnclaimedGatingTest.php`

**Interfaces:**
- Produces: published unclaimed sites resolve publicly; everything else stays fail-closed. (KV-job routability is Task 13 — it needs `expires_at` TTL context.)

- [ ] **Step 1: Write the failing tests** (`tests/Feature/PreAccount/UnclaimedGatingTest.php`)

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\PublicSite\PublicSiteResolver;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function makeUnclaimedWithSite(array $siteAttrs = []): array
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    $site = Site::factory()->create(array_merge(['user_id' => $user->id, 'is_published' => true], $siteAttrs));

    return [$user, $site];
}

it('resolves a PUBLISHED unclaimed site publicly (the marketing pitch)', function () {
    [, $site] = makeUnclaimedWithSite(['subdomain' => 'janedoe']);

    $result = app(PublicSiteResolver::class)->resolvePublishedSite('janedoe');
    expect($result['site']?->id)->toBe($site->id);
});

it('does NOT resolve an UNPUBLISHED unclaimed site (signup-path default)', function () {
    makeUnclaimedWithSite(['subdomain' => 'janedoe', 'is_published' => false]);

    expect(app(PublicSiteResolver::class)->resolvePublishedSite('janedoe')['site'])->toBeNull();
});

it('still refuses suspended/disabled owners', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true, 'subdomain' => 'suss']);

    expect(app(PublicSiteResolver::class)->resolvePublishedSite('suss')['site'])->toBeNull();
});

it('staff force-delete works on an unclaimed user (the manual expiry — spec §7)', function () {
    // Arrange a staff actor with fresh AAL2 exactly as StaffUserController forceDestroy
    // tests do (grep -rln "forceDestroy" tests/Feature/Staff and copy the arrange).
    [$user] = makeUnclaimedWithSite(['subdomain' => 'takedown']);

    // DELETE /staff/professionals/{id}/force → 200; user hard-deleted.
    // Assert User::withTrashed()->find($user->id) is null after the call.
});
```

(Complete the staff force-delete test body from the existing `forceDestroy` feature-test arrange — it exercises the `staffForceDelete` policy + fresh-AAL2 gate against a `status='unclaimed'` row; no production code change is expected, this is a pin that the manual-expiry path already works for the new status.)

(Adapt factory usage to whatever `setupSitesTable()`-era tests do today — copy the arrange pattern from an existing `PublicSiteResolver` test if one exists: `grep -rln "resolvePublishedSite" tests/`.)

- [ ] **Step 2: Run** — Expected: first test FAILS (unclaimed filtered out), others pass.

- [ ] **Step 3: Implement** — in `PublicSiteResolver`:

```php
            ->whereHas('user', function ($q) {
                // Unclaimed (pre-account) owners render when published — the
                // publish knob, not claim state, controls visibility (spec §2).
                $q->whereIn('status', ['active', 'unclaimed']);
            });
```

- [ ] **Step 4: Grep for sibling public-read status gates** — `git grep -n "'status'.*active" app/Services/PublicSite app/Services/Cache app/Http/Controllers/Api/PublicSite`. Decision rule: widen ONLY read-path gates that decide whether a public sitepage/payload renders (mirror the change above); leave every capability/notification/deletion gate untouched (fail-closed is correct there). Document each hit + decision in the test file's header comment.

- [ ] **Step 5: Run the new tests (PASS) + full suite** `composer test` — Expected: green.

- [ ] **Step 6: Commit** — `git commit -m "feat(pre-account): published unclaimed sites resolve on the public read path"`

---

# Phase 2 — Build engine

### Task 5: Extract `HandleAllocator` + `EmailReuseGuard` (pure refactor)

**Files:**
- Create: `app/Services/User/HandleAllocator.php`, `app/Services/User/EmailReuseGuard.php`
- Modify: `app/Http/Requests/Api/BootstrapRequest.php:158-176`, `app/Services/User/UserBootstrapService.php:156-180`
- Test: `tests/Unit/Services/HandleAllocatorTest.php`

**Interfaces:**
- Produces: `HandleAllocator::allocate(string $seed): array{handle: string, handle_lc: string}` (slug + bare-integer collision suffix, exactly `generateHandleFromDisplayName`'s behavior); `EmailReuseGuard::isClaimedByAnotherAuthUser(string $email, string $uid): bool` (exact logic from `UserBootstrapService::emailIsClaimedByAnotherAuthUser`).
- Consumed by: `BootstrapRequest`, `UserBootstrapService` (unchanged behavior), `PreAccountBuildService` (Task 7), `ClaimSiteService` (Task 11).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\User\User;
use App\Services\User\HandleAllocator;

beforeEach(fn () => setupUsersTable());

it('slugs the seed and suffixes bare integers on collision (bootstrap-identical)', function () {
    $alloc = app(HandleAllocator::class);

    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'jane-doe', 'handle_lc' => 'jane-doe']);

    User::factory()->create(['handle' => 'jane-doe', 'handle_lc' => 'jane-doe']);
    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'jane-doe1', 'handle_lc' => 'jane-doe1']);
});

it('falls back to "professional" for an empty slug', function () {
    expect(app(HandleAllocator::class)->allocate('$$$')['handle'])->toBe('professional');
});
```

- [ ] **Step 2: Run** — Expected: FAIL (class missing).

- [ ] **Step 3: Implement**

`app/Services/User/HandleAllocator.php`:

```php
<?php

namespace App\Services\User;

use App\Models\Core\User\User;
use Illuminate\Support\Str;

// Extracted verbatim from BootstrapRequest::generateHandleFromDisplayName so the
// pre-account build path can allocate handles without a Form Request in scope.
// Behavior contract: slug, 'professional' fallback, BARE-integer collision suffix
// (jane-doe1 — distinct from the subdomain's hyphenated -1 style, deliberately).
class HandleAllocator
{
    /** @return array{handle: string, handle_lc: string} */
    public function allocate(string $seed): array
    {
        $base = Str::slug($seed);
        if ($base === '' || $base === '-') {
            $base = 'professional';
        }

        $handle = $base;
        $attempt = 1;
        while (User::query()->where('handle_lc', strtolower($handle))->exists()) {
            $handle = $base.$attempt;
            $attempt++;
        }

        return ['handle' => $handle, 'handle_lc' => strtolower($handle)];
    }
}
```

`app/Services/User/EmailReuseGuard.php`:

```php
<?php

namespace App\Services\User;

use App\Models\Core\User\User;

// Extracted from UserBootstrapService::emailIsClaimedByAnotherAuthUser so the
// claim flow shares the exact same case-insensitive reuse check.
class EmailReuseGuard
{
    public function isClaimedByAnotherAuthUser(string $email, string $uid): bool
    {
        $emailLc = strtolower(trim($email));
        if ($emailLc === '') {
            return false;
        }

        return User::query()
            ->whereRaw('lower(primary_email) = ?', [$emailLc])
            ->where('auth_user_id', '!=', $uid)
            ->exists();
    }
}
```

Then in `BootstrapRequest::generateHandleFromDisplayName` replace the body with `return app(HandleAllocator::class)->allocate($displayName)['handle'];` and in `UserBootstrapService` replace `emailIsClaimedByAnotherAuthUser($email, $uid)` calls with an injected `EmailReuseGuard` (constructor property) and delete the private method.

- [ ] **Step 4: Run** the new test (PASS) **and the FULL suite** `composer test` — pure refactor, everything must stay green (namespace-relocation rule: full suite, never a filtered subset).

- [ ] **Step 5: Commit** — `git commit -m "refactor(pre-account): extract HandleAllocator + EmailReuseGuard shared seams"`

### Task 6: `SiteProvisioningService` publish parameter

**Files:**
- Modify: `app/Services/User/SiteProvisioningService.php:14,85-123`
- Test: `tests/Unit/Services/SiteProvisioningServiceTest.php` (extend the existing test file if one exists — `grep -rln "createSiteWithRetry" tests/`)

**Interfaces:**
- Produces: `createSiteWithRetry(string $userId, string $base, bool $published = true): Site` — default preserves every existing caller's behavior.

- [ ] **Step 1: Write the failing test**

```php
it('creates an unpublished site when asked (pre-account signup builds)', function () {
    setupUsersTable();
    setupSitesTable();
    $user = User::factory()->create();

    $site = app(SiteProvisioningService::class)->createSiteWithRetry($user->id, 'janedoe', published: false);

    expect($site->is_published)->toBeFalse()->and($site->subdomain)->toBe('janedoe');
});
```

- [ ] **Step 2: Run** — Expected: FAIL (unknown named argument).

- [ ] **Step 3: Implement** — thread the flag through:

```php
public function createSiteWithRetry(string $userId, string $base, bool $published = true): Site
```
…pass `$published` down to `tryCreateSite(string $userId, string $candidate, bool $published)` and set `'is_published' => $published` in the `new Site([...])` array.

- [ ] **Step 4: Run** new test + full suite — Expected: green (default `true` keeps bootstrap behavior byte-identical).

- [ ] **Step 5: Commit** — `git commit -m "feat(pre-account): SiteProvisioningService publish flag"`

### Task 7: Config + `PreAccountBuildService`

**Files:**
- Create: `app/Services/PreAccount/PreAccountBuildService.php`, `app/Services/PreAccount/PreAccountBuildException.php`
- Modify: `config/partna.php`
- Test: `tests/Feature/PreAccount/PreAccountBuildServiceTest.php`

**Interfaces:**
- Consumes: `HandleAllocator`, `SiteProvisioningService::createSiteWithRetry(..., published:)`, `SourceGeneratorRegistry` (Task 8 — for `normalizeRef`/`dedupeKey`/`handleSeed`; this task lands with a minimal registry stub as written below, completed in Task 8).
- Produces: `requestBuild(string $accountType, string $sourceType, string $rawSourceRef, ?string $sourceName, ?string $ipHash, ?PartnaStaff $staff = null, bool $publish = false, ?int $expiresDays = null): array{build: PreAccountBuild, reused: bool}`; throws `PreAccountBuildException` with `public readonly string $errorCode` ∈ `SOURCE_PAIRING_INVALID | IP_BUILD_CAP | SOURCE_REF_INVALID`.

- [ ] **Step 1: Add config** (`config/partna.php`, new top-level `pre_account` block near the `waitlist` block):

```php
    // Pre-Account Sites (site-first signup + staff marketing builds).
    'pre_account' => [
        'expiry_days' => (int) env('PARTNA_PRE_ACCOUNT_EXPIRY_DAYS', 30),
        'failed_prune_hours' => (int) env('PARTNA_PRE_ACCOUNT_FAILED_PRUNE_HOURS', 24),
        'max_unclaimed_per_ip' => (int) env('PARTNA_PRE_ACCOUNT_MAX_UNCLAIMED_PER_IP', 3),

        // account_type => allowed source_types. THE one pairing map (spec §4) —
        // relaxing a pairing later is a config edit, not a validation hunt.
        'sources' => [
            'partna' => ['instagram'],
            'business' => ['google_business'],
        ],

        // source_type => generator class (registry key; a third source is one
        // class + one CHECK widening).
        'generators' => [
            'instagram' => \App\Services\PreAccount\Generators\InstagramSourceGenerator::class,
            'google_business' => \App\Services\PreAccount\Generators\GoogleBusinessSourceGenerator::class,
        ],
    ],
```

Mirror the two new env keys into `.env.example` beside the other `PARTNA_*` keys.

- [ ] **Step 2: Write the failing tests**

```php
<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    Queue::fake();
});

it('creates provisional user + unpublished site + pending build and dispatches the job', function () {
    $result = app(PreAccountBuildService::class)->requestBuild(
        accountType: 'partna', sourceType: 'instagram', rawSourceRef: '@JaneDoe',
        sourceName: null, ipHash: hash('sha256', '1.2.3.4'),
    );

    $build = $result['build'];
    expect($result['reused'])->toBeFalse()
        ->and($build->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($build->source_ref)->toBe('janedoe')          // IG normalization strips @ + lowercases
        ->and($build->source_ref_lc)->toBe('janedoe')
        ->and($build->built_via)->toBe(PreAccountBuild::VIA_SIGNUP);

    $user = $build->user;
    expect($user->status)->toBe('unclaimed')
        ->and($user->auth_user_id)->toBeNull()
        ->and($user->primary_email)->toBeNull()
        ->and($user->account_type->value)->toBe('partna')
        ->and($user->first_name)->not->toBeNull()            // NOT NULL on live Postgres
        ->and($user->site->is_published)->toBeFalse()
        ->and($user->site->subdomain)->toBe('janedoe');

    Queue::assertPushed(GeneratePreAccountSiteJob::class, fn ($job) => $job->buildId === $build->id);
});

it('re-serves an existing LIVE build for the same source without re-scraping', function () {
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'janedoe', null, hash('sha256', 'a'));
    $second = $svc->requestBuild('business', 'instagram', '@JANEDOE', null, hash('sha256', 'b'));

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->id)->toBe($first['build']->id)
        // re-served build keeps its ORIGINAL account_type (spec §4.1)
        ->and($second['build']->user->account_type->value)->toBe('partna');
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 1);
});

it('retries a failed live build on dedupe hit (F3)', function () {
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'janedoe', null, hash('sha256', 'a'));
    $first['build']->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => 'scrape_failed']);

    $second = $svc->requestBuild('partna', 'instagram', 'janedoe', null, hash('sha256', 'a'));
    expect($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($second['build']->fresh()->failure_code)->toBeNull();
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
});

it('rejects a wrong account_type/source_type pairing from the config map', function () {
    app(PreAccountBuildService::class)->requestBuild('partna', 'google_business', 'x', 'Cafe', hash('sha256', 'a'));
})->throws(PreAccountBuildException::class);

it('caps outstanding unclaimed builds per IP', function () {
    config(['partna.pre_account.max_unclaimed_per_ip' => 1]);
    $svc = app(PreAccountBuildService::class);
    $svc->requestBuild('partna', 'instagram', 'first', null, hash('sha256', 'same-ip'));

    $svc->requestBuild('partna', 'instagram', 'second', null, hash('sha256', 'same-ip'));
})->throws(PreAccountBuildException::class);

it('staff builds record the staff id, skip the IP cap, and honour expires_days', function () {
    $staff = makePartnaStaff(); // copy the arrange helper used by existing staff feature tests
    $result = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'prospect', null, null,
        staff: $staff, publish: true, expiresDays: 60,
    );

    expect($result['build']->built_via)->toBe(PreAccountBuild::VIA_STAFF)
        ->and($result['build']->built_by_staff_id)->toBe($staff->id)
        ->and($result['build']->expires_at->isAfter(now()->addDays(59)))->toBeTrue();
});
```

(For `makePartnaStaff()`: find the existing staff-test arrange pattern with `grep -rln "PartnaStaff::" tests/Feature/Staff | head -3` and copy it.)

- [ ] **Step 3: Run** — Expected: FAIL (service missing).

- [ ] **Step 4: Implement**

`app/Services/PreAccount/PreAccountBuildException.php`:

```php
<?php

namespace App\Services\PreAccount;

use RuntimeException;

class PreAccountBuildException extends RuntimeException
{
    public const SOURCE_PAIRING_INVALID = 'SOURCE_PAIRING_INVALID';
    public const IP_BUILD_CAP = 'IP_BUILD_CAP';
    public const SOURCE_REF_INVALID = 'SOURCE_REF_INVALID';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
```

`app/Services/PreAccount/PreAccountBuildService.php`:

```php
<?php

namespace App\Services\PreAccount;

use App\Enums\AccountType;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\User\HandleAllocator;
use App\Services\User\SiteProvisioningService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PreAccountBuildService
{
    public function __construct(
        private readonly SourceGeneratorRegistry $generators,
        private readonly HandleAllocator $handles,
        private readonly SiteProvisioningService $siteProvisioning,
    ) {}

    /**
     * Create (or re-serve) a pre-account build for a typed source ref.
     *
     * @return array{build: PreAccountBuild, reused: bool}
     *
     * @throws PreAccountBuildException
     */
    public function requestBuild(
        string $accountType,
        string $sourceType,
        string $rawSourceRef,
        ?string $sourceName,
        ?string $ipHash,
        ?PartnaStaff $staff = null,
        bool $publish = false,
        ?int $expiresDays = null,
    ): array {
        // ONE config map decides which account type may build from which source.
        $allowed = config("partna.pre_account.sources.{$accountType}", []);
        if (! in_array($sourceType, $allowed, true)) {
            throw new PreAccountBuildException(
                PreAccountBuildException::SOURCE_PAIRING_INVALID,
                "Source '{$sourceType}' is not available for '{$accountType}' accounts."
            );
        }

        $generator = $this->generators->for($sourceType);
        try {
            $ref = $generator->normalizeRef($rawSourceRef);
        } catch (\InvalidArgumentException $e) {
            throw new PreAccountBuildException(PreAccountBuildException::SOURCE_REF_INVALID, $e->getMessage());
        }
        $refLc = $generator->dedupeKey($ref);

        // Dedupe: one LIVE build per source. Failed live builds retry (F3).
        if ($existing = $this->findLive($sourceType, $refLc)) {
            return ['build' => $this->reserve($existing), 'reused' => true];
        }

        // Signup-path abuse cap: outstanding unclaimed builds per IP (F2).
        if ($staff === null && $ipHash !== null) {
            $cap = (int) config('partna.pre_account.max_unclaimed_per_ip', 3);
            $outstanding = PreAccountBuild::live()->where('created_ip_hash', $ipHash)->count();
            if ($outstanding >= $cap) {
                throw new PreAccountBuildException(
                    PreAccountBuildException::IP_BUILD_CAP,
                    'Too many unclaimed builds from this address. Claim one first.'
                );
            }
        }

        $expiresAt = now()->addDays($expiresDays ?? (int) config('partna.pre_account.expiry_days', 30));

        try {
            $build = DB::connection('pgsql')->transaction(function () use (
                $accountType, $sourceType, $ref, $refLc, $sourceName, $ipHash, $staff, $expiresAt
            ) {
                $seed = $this->generators->for($sourceType)->handleSeed($ref, $sourceName);
                $handle = $this->handles->allocate($seed);

                $user = new User([
                    'handle' => $handle['handle'],
                    'handle_lc' => $handle['handle_lc'],
                    // Placeholder identity until the generator writes scraped values;
                    // first_name/display_name are NOT NULL on live Postgres.
                    'display_name' => $sourceName ?: $handle['handle'],
                    'first_name' => $sourceName ?: $handle['handle'],
                    'account_type' => AccountType::tryFrom($accountType) ?? AccountType::Partna,
                    'status' => 'unclaimed',
                    'onboarding_step' => 0,
                ]);
                // auth_user_id stays NULL — that IS the provisional-user model.
                $user->save();

                // Real subdomain at build time, unpublished for signup builds; the
                // staff publish knob flips AFTER generation succeeds (in the job).
                $this->siteProvisioning->createSiteWithRetry(
                    $user->id,
                    $this->siteProvisioning->subdomainBaseFromHandle($seed),
                    published: false,
                );

                $build = new PreAccountBuild([
                    'source_type' => $sourceType,
                    'source_ref' => $ref,
                    'source_ref_lc' => $refLc,
                    'built_via' => $staff ? PreAccountBuild::VIA_STAFF : PreAccountBuild::VIA_SIGNUP,
                    'created_ip_hash' => $staff ? null : $ipHash,
                    'expires_at' => $expiresAt,
                ]);
                $build->user()->associate($user);
                if ($staff) {
                    $build->builtByStaff()->associate($staff);
                }
                $build->save();

                return $build;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race on pre_account_builds_live_source_unique — the other
            // request's build is the canonical one; re-serve it (spec §4.1).
            $existing = $this->findLive($sourceType, $refLc);
            if ($existing) {
                return ['build' => $this->reserve($existing), 'reused' => true];
            }
            throw new PreAccountBuildException(
                PreAccountBuildException::SOURCE_REF_INVALID,
                'Could not create the build. Try again.'
            );
        }

        GeneratePreAccountSiteJob::dispatch($build->id, $publish)->afterCommit();

        return ['build' => $build, 'reused' => false];
    }

    private function findLive(string $sourceType, string $refLc): ?PreAccountBuild
    {
        return PreAccountBuild::live()
            ->where('source_type', $sourceType)
            ->where('source_ref_lc', $refLc)
            ->first();
    }

    /** Re-serve a live build; a FAILED one resets and re-runs (F3). */
    private function reserve(PreAccountBuild $build): PreAccountBuild
    {
        if ($build->build_state === PreAccountBuild::STATE_FAILED) {
            $build->update(['build_state' => PreAccountBuild::STATE_PENDING, 'failure_code' => null]);
            GeneratePreAccountSiteJob::dispatch($build->id, false)->afterCommit();
        }

        return $build;
    }
}
```

Note: SQLite does not enforce the partial unique index, so the race test relies on the dedupe query; the 23505 catch is verified by the DDL (Task 1 Step 4) per the drift rule. The `GeneratePreAccountSiteJob` class doesn't exist until Task 10 — create it in this task as the minimal shell below so the service compiles, and flesh it out in Task 10:

```php
<?php

namespace App\Jobs\PreAccount;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePreAccountSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [30];

    public function __construct(
        public readonly string $buildId,
        public readonly bool $publish = false,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function handle(): void
    {
        // Implemented in the generator-job task.
    }
}
```

- [ ] **Step 5: Run** the tests — Expected: PASS. Run `./vendor/bin/pest tests/Feature/Queue/JobHygienePolicyTest.php` — Expected: PASS (shell job declares tries/timeout/backoff).

- [ ] **Step 6: Commit** — `git commit -m "feat(pre-account): PreAccountBuildService + config + job shell"`

### Task 8: Generator interface, registry, Instagram seeder extraction + `InstagramSourceGenerator`

**Files:**
- Create: `app/Services/PreAccount/Generators/SiteSourceGenerator.php`, `app/Services/PreAccount/SourceGeneratorRegistry.php`, `app/Services/PreAccount/SourceGenerationException.php`, `app/Services/Platforms/InstagramConnectionSeeder.php`, `app/Services/PreAccount/Generators/InstagramSourceGenerator.php`
- Modify: `app/Jobs/Platforms/InstagramConnectJob.php` (delegate to seeder)
- Test: `tests/Feature/PreAccount/InstagramSourceGeneratorTest.php`

**Interfaces:**
- Produces:

```php
interface SiteSourceGenerator
{
    /** Canonicalize the typed ref (IG: strip @/trim/lowercase). @throws \InvalidArgumentException */
    public function normalizeRef(string $raw): string;

    /** Dedupe key for pre_account_builds.source_ref_lc (IG: same as ref; GBP: exact place_id). */
    public function dedupeKey(string $normalizedRef): string;

    /** Seed for handle/subdomain/display-name (IG: the handle; GBP: source_name — F1). */
    public function handleSeed(string $normalizedRef, ?string $sourceName): string;

    /**
     * Populate user profile fields + site content from the source.
     * @throws SourceGenerationException
     */
    public function generate(\App\Models\Core\User\User $user, \App\Models\Core\Site\Site $site, string $sourceRef): void;
}
```

- `SourceGenerationException` carries `public readonly string $failureCode` (`PreAccountBuild::FAILURE_*`), with static constructors `sourceNotFound()` / `scrapeFailed(string $detail = '')`.
- `SourceGeneratorRegistry::for(string $sourceType): SiteSourceGenerator` — resolves `config('partna.pre_account.generators')[$sourceType]` from the container; throws `InvalidArgumentException` on unknown type.
- `InstagramConnectionSeeder::seed(IntegrationConnection $connection, string $username, string $userId, array $profile): array` — the mirror + selection-build + auto-sync + row-update body currently inlined in `InstagramConnectJob::handle()` (lines 149-261) and its private helpers `mirrorOne`/`mirrorVideo`/`isAllowedHost` and constants, moved verbatim. Returns the persisted `$selection`.

- [ ] **Step 1: Extract the seeder (refactor first, no behavior change).** Move lines 149-261 of `InstagramConnectJob::handle()` plus `mirrorOne`, `mirrorVideo`, `isAllowedHost`, and the `ALLOWED_HOSTS` / timeout / byte-cap constants into `App\Services\Platforms\InstagramConnectionSeeder` with the signature above. `InstagramConnectJob::handle()` becomes:

```php
    public function handle(InstagramScraper $scraper, InstagramConnectionSeeder $seeder, InstagramAutoSync $autoSync): void
    {
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection) {
            return;
        }

        $profile = $scraper->fetchProfile($this->username, $this->userId);

        if (! $profile) {
            $this->fail(new \RuntimeException(
                "Instagram scrape returned no profile for @{$this->username} (user {$this->userId})"
            ));

            return;
        }

        $seeder->seed($connection, $this->username, $this->userId, $profile);
    }
```

(The seeder constructor-injects `InstagramScraper` + `InstagramAutoSync` for `latestMedia`/`profilePicUrl`/`bioLinks`/`seed` calls; keep every comment with the code it explains.)

- [ ] **Step 2: Run the FULL suite** `composer test` — Expected: green. This is the checkpoint that the extraction changed nothing (existing InstagramConnectJob tests are the safety net). Commit: `git commit -m "refactor(platforms): extract InstagramConnectionSeeder from InstagramConnectJob"`

- [ ] **Step 3: Write the failing generator tests**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramScraper;
use App\Services\PreAccount\Generators\InstagramSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable(); // find the actual helper name: grep -n "integration_connections" tests/Pest.php
});

it('normalizes typed refs', function () {
    $gen = app(InstagramSourceGenerator::class);
    expect($gen->normalizeRef(' @JaneDoe '))->toBe('janedoe')
        ->and($gen->dedupeKey('janedoe'))->toBe('janedoe')
        ->and($gen->handleSeed('janedoe', null))->toBe('janedoe');
    $gen->normalizeRef('   ');
})->throws(InvalidArgumentException::class);

it('scrapes, seeds a connection, and writes profile fields onto the provisional user', function () {
    // IMPORTANT (repo gotcha): bind scraper mocks BEFORE any IntegrationConnection
    // is saved — the SEC-1 saving-guard resolves PlatformRegistry eagerly on first save.
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->with('janedoe', Mockery::type('string'))
        ->andReturn(['fullName' => 'Jane Doe', 'biography' => 'Hair by Jane']);
    // Seeder path: stub the mirror-level collaborators via the seeder itself — mock it
    // wholesale; its own behavior is covered by InstagramConnectJob's existing tests.
    $seeder = Mockery::mock(\App\Services\Platforms\InstagramConnectionSeeder::class);
    $seeder->shouldReceive('seed')->once()->andReturn(['fullName' => 'Jane Doe']);
    app()->instance(InstagramScraper::class, $scraper);
    app()->instance(\App\Services\Platforms\InstagramConnectionSeeder::class, $seeder);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'display_name' => 'janedoe', 'first_name' => 'janedoe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(InstagramSourceGenerator::class)->generate($user, $site, 'janedoe');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeTrue()
        ->and($user->fresh()->display_name)->toBe('Jane Doe')
        ->and($user->fresh()->first_name)->toBe('Jane');
});

it('maps a missing profile to source_not_found', function () {
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturnNull();
    app()->instance(InstagramScraper::class, $scraper);

    $user = User::factory()->create(['status' => 'unclaimed']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    try {
        app(InstagramSourceGenerator::class)->generate($user, $site, 'ghost');
        $this->fail('expected SourceGenerationException');
    } catch (SourceGenerationException $e) {
        expect($e->failureCode)->toBe(\App\Models\Core\User\PreAccountBuild::FAILURE_SOURCE_NOT_FOUND);
    }
});
```

- [ ] **Step 4: Run** — Expected: FAIL (classes missing).

- [ ] **Step 5: Implement**

`SourceGenerationException`:

```php
<?php

namespace App\Services\PreAccount;

use App\Models\Core\User\PreAccountBuild;
use RuntimeException;

class SourceGenerationException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }

    public static function sourceNotFound(): self
    {
        return new self(PreAccountBuild::FAILURE_SOURCE_NOT_FOUND, 'Source profile not found.');
    }

    public static function scrapeFailed(string $detail = ''): self
    {
        return new self(PreAccountBuild::FAILURE_SCRAPE_FAILED, 'Source scrape failed. '.$detail);
    }
}
```

`SourceGeneratorRegistry`:

```php
<?php

namespace App\Services\PreAccount;

use App\Services\PreAccount\Generators\SiteSourceGenerator;

class SourceGeneratorRegistry
{
    public function for(string $sourceType): SiteSourceGenerator
    {
        $class = config("partna.pre_account.generators.{$sourceType}");
        if (! is_string($class) || ! is_a($class, SiteSourceGenerator::class, true)) {
            throw new \InvalidArgumentException("Unknown pre-account source type '{$sourceType}'.");
        }

        return app($class);
    }
}
```

`InstagramSourceGenerator`:

```php
<?php

namespace App\Services\PreAccount\Generators;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\Registry\Platform;
use App\Services\PreAccount\SourceGenerationException;
use Illuminate\Support\Str;

// Builds a provisional user's site from a typed Instagram handle by reusing the
// EXACT connect machinery an authenticated user gets: a pending IntegrationConnection
// seeded by InstagramConnectionSeeder (scrape → mirror to R2 → payload write). The
// unclaimed site therefore renders identically to a connected user's site, and the
// IntegrationConnectionObserver flips content_instagram_auto_enabled on create.
class InstagramSourceGenerator implements SiteSourceGenerator
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramConnectionSeeder $seeder,
    ) {}

    public function normalizeRef(string $raw): string
    {
        $ref = mb_strtolower(ltrim(trim($raw), '@'));
        if ($ref === '' || ! preg_match('/^[a-z0-9._]{1,30}$/', $ref)) {
            throw new \InvalidArgumentException('That does not look like an Instagram handle.');
        }

        return $ref;
    }

    public function dedupeKey(string $normalizedRef): string
    {
        return $normalizedRef; // already lowercase
    }

    public function handleSeed(string $normalizedRef, ?string $sourceName): string
    {
        return $normalizedRef;
    }

    public function generate(User $user, Site $site, string $sourceRef): void
    {
        $profile = $this->scraper->fetchProfile($sourceRef, $user->id);
        if (! $profile) {
            throw SourceGenerationException::sourceNotFound();
        }

        // Pending placeholder mirroring InstagramController::connect — payload []
        // (NOT null: platform_connections.payload is NOT NULL on live Postgres).
        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => Platform::Instagram->value,
                'resource_id' => 'default', // VERIFY: mirror ManagesIntegrationConnection::defaultResourceId()
            ],
            [
                'payload' => [],
                'is_active' => false,
                'last_refreshed_at' => null,
                'last_refresh_status' => 'pending',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );

        try {
            $this->seeder->seed($connection, $sourceRef, $user->id, $profile);
        } catch (\Throwable $e) {
            throw SourceGenerationException::scrapeFailed($e->getMessage());
        }

        // Scraped identity onto the user row (spec §4): placeholder → real values.
        $fullName = trim((string) data_get($profile, 'fullName'));
        if ($fullName !== '') {
            $user->display_name = $fullName;
            $user->first_name = Str::before($fullName, ' ') ?: $fullName;
            $user->save();
        }
    }
}
```

Implementation notes for this task: (a) verify the `resource_id` literal by reading `ManagesIntegrationConnection::defaultResourceId()` (`grep -n "defaultResourceId" app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`) and use the same value; (b) if `IntegrationConnection` doesn't allow `user_id` in `updateOrCreate` attributes, mirror exactly how `InstagramController::connect()` (lines 74-91) passes it.

- [ ] **Step 6: Run** the generator tests (PASS) + full suite — Expected: green.

- [ ] **Step 7: Commit** — `git commit -m "feat(pre-account): source generator contract + Instagram generator"`

### Task 9: `GoogleBusinessSourceGenerator`

**Files:**
- Create: `app/Services/PreAccount/Generators/GoogleBusinessSourceGenerator.php`
- Test: `tests/Feature/PreAccount/GoogleBusinessSourceGeneratorTest.php`

**Interfaces:**
- Consumes: `GoogleBusinessService::fetchPlaceDetails(string $placeId): ?array`, `IdentitySync::applyFromGooglePayload(User $user, array $gbPayload): void`, `GoogleBusinessEnrichJob::dispatch(string $userId, string $placeId)`.
- Produces: workplace row + google-business `IntegrationConnection` for the provisional user.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\PreAccount\Generators\GoogleBusinessSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
    setupWorkplacesTable(); // find actual helper name in tests/Pest.php
});

it('fetches place details, seeds a connection, and folds identity into the workplace', function () {
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->with('ChIJtest123')
        ->andReturn(['name' => 'Jane Cafe', 'address' => '1 Main St', 'phone' => '+61 400 000 000', 'website' => 'https://janecafe.au']);
    app()->instance(GoogleBusinessService::class, $svc);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business', 'display_name' => 'Jane Cafe', 'first_name' => 'Jane Cafe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJtest123');

    expect(Workplace::where('site_id', $site->id)->value('name'))->toBe('Jane Cafe')
        ->and(IntegrationConnection::where('user_id', $user->id)->where('platform', 'google-business')->exists())->toBeTrue();
});

it('maps a null details response to source_not_found', function () {
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->andReturnNull();
    app()->instance(GoogleBusinessService::class, $svc);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    try {
        app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJgone');
        $this->fail('expected SourceGenerationException');
    } catch (SourceGenerationException $e) {
        expect($e->failureCode)->toBe(\App\Models\Core\User\PreAccountBuild::FAILURE_SOURCE_NOT_FOUND);
    }
});
```

(Same SEC-1 mock-before-save gotcha applies. Verify the `Workplace` name cap — `name` max:15 in the Form Request is a DASHBOARD rule; `IdentitySync` writes column-direct, check `Workplace` model/DDL for any DB-level length limit and truncate in the generator only if the DDL requires it.)

- [ ] **Step 2: Run** — Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\PreAccount\Generators;

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\IdentitySync;
use App\Services\Platforms\Registry\Platform;
use App\Services\PreAccount\SourceGenerationException;
use Illuminate\Support\Str;

// Builds a provisional business user's site from a Google Business Profile
// place_id via the EXISTING Places + IdentitySync machinery: fetch details with
// the server key, persist a google-business connection (same shape as
// GoogleBusinessController::connect), fold identity into site.workplaces, and
// kick the Apify enrichment job when a token is configured.
class GoogleBusinessSourceGenerator implements SiteSourceGenerator
{
    public function __construct(
        private readonly GoogleBusinessService $service,
        private readonly IdentitySync $identitySync,
    ) {}

    public function normalizeRef(string $raw): string
    {
        $ref = trim($raw);
        if ($ref === '' || mb_strlen($ref) > 300) {
            throw new \InvalidArgumentException('That does not look like a Google place id.');
        }

        return $ref;
    }

    public function dedupeKey(string $normalizedRef): string
    {
        return $normalizedRef; // place_ids are case-sensitive — never case-fold them
    }

    public function handleSeed(string $normalizedRef, ?string $sourceName): string
    {
        // A place_id is opaque; the business name (F1, required at validation)
        // seeds the handle/subdomain.
        return $sourceName ?: 'business';
    }

    public function generate(User $user, Site $site, string $sourceRef): void
    {
        $details = $this->service->fetchPlaceDetails($sourceRef);
        if ($details === null) {
            // Covers both a bad/stale place_id and a missing server key — the
            // service is best-effort-null either way; the build must not hang.
            throw SourceGenerationException::sourceNotFound();
        }

        $name = trim((string) ($details['name'] ?? '')) ?: $user->display_name;

        $payload = [
            'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($name).'&query_place_id='.rawurlencode($sourceRef),
            'placeId' => $sourceRef,
            'name' => $name,
            ...$details,
        ];

        // Same row shape GoogleBusinessController::connect persists.
        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => Platform::GoogleBusiness->value, // VERIFY enum case + literal
                'resource_id' => 'default', // VERIFY: mirror ManagesIntegrationConnection::defaultResourceId()
            ],
            [
                'payload' => $payload,
                'is_active' => true,
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );

        $enrich = (bool) config('services.apify.token');
        $connection->forceFill([
            'place_id' => $sourceRef,
            'apify_status' => $enrich ? 'pending' : null,
        ])->saveQuietly();

        // Identity fold: business accounts get full overwrite via the capability
        // (AccountCapabilities::google_business_full_sync) — same engine as connect.
        $this->identitySync->applyFromGooglePayload($user, $payload);

        // Business accounts adopt the Google name as display name (capability-gated,
        // mirroring GoogleBusinessController::maybeAdoptGoogleName).
        if ($name !== '' && AccountCapabilities::for($user)->google_business_sets_display_name) {
            $user->display_name = $name;
            $user->first_name = Str::before($name, ' ') ?: $name;
            $user->save();
        }

        if ($enrich) {
            GoogleBusinessEnrichJob::dispatch((string) $user->id, $sourceRef);
        }
    }
}
```

Implementation notes: verify (a) the `Platform` enum case for google-business (`grep -n "GoogleBusiness\|google-business" app/Services/Platforms/Registry/Platform.php`), (b) the capability property name (`grep -n "google_business_sets_display_name\|google_business_full_sync" app/Services/Accounts/AccountCapabilities.php`), (c) `GoogleBusinessEnrichJob`'s constructor signature before dispatching.

- [ ] **Step 4: Run** tests (PASS) + full suite. **Step 5: Commit** — `git commit -m "feat(pre-account): Google Business source generator"`

### Task 10: `GeneratePreAccountSiteJob` (full implementation)

**Files:**
- Modify: `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php` (from Task 7 shell)
- Test: `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php`

**Interfaces:**
- Consumes: `SourceGeneratorRegistry`, `SyncSubdomainToKvJob::dispatch(string $userId)`.
- Produces: build_state transitions `pending → building → ready|failed`; staff-publish flips `is_published` + re-syncs KV.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

function makePendingBuild(bool $publish = false): PreAccountBuild
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);
    $build = PreAccountBuild::factory()->make();
    $build->user()->associate($user);
    $build->save();

    return $build;
}

function bindGenerator(?Closure $behaviour = null): void
{
    $gen = Mockery::mock(SiteSourceGenerator::class);
    $exp = $gen->shouldReceive('generate')->once();
    if ($behaviour) {
        $exp->andReturnUsing($behaviour);
    }
    config(['partna.pre_account.generators.instagram' => get_class($gen)]);
    app()->instance(get_class($gen), $gen);
}

it('runs the generator and flips pending → ready', function () {
    $build = makePendingBuild();
    bindGenerator();

    (new GeneratePreAccountSiteJob($build->id))->handle(app(\App\Services\PreAccount\SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
});

it('records failure_code and flips to failed on SourceGenerationException', function () {
    $build = makePendingBuild();
    bindGenerator(fn () => throw SourceGenerationException::sourceNotFound());

    (new GeneratePreAccountSiteJob($build->id))->handle(app(\App\Services\PreAccount\SourceGeneratorRegistry::class));

    $fresh = $build->fresh();
    expect($fresh->build_state)->toBe(PreAccountBuild::STATE_FAILED)
        ->and($fresh->failure_code)->toBe(PreAccountBuild::FAILURE_SOURCE_NOT_FOUND);
});

it('publishes the site + re-syncs KV for staff publish builds', function () {
    Queue::fake([SyncSubdomainToKvJob::class]);
    $build = makePendingBuild();
    bindGenerator();

    (new GeneratePreAccountSiteJob($build->id, publish: true))->handle(app(\App\Services\PreAccount\SourceGeneratorRegistry::class));

    expect($build->user->fresh()->site->is_published)->toBeTrue();
    Queue::assertPushed(SyncSubdomainToKvJob::class);
});

it('no-ops on a claimed or already-ready build', function () {
    $build = makePendingBuild();
    $build->update(['claimed_at' => now(), 'build_state' => PreAccountBuild::STATE_READY]);

    (new GeneratePreAccountSiteJob($build->id))->handle(app(\App\Services\PreAccount\SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
});
```

- [ ] **Step 2: Run** — Expected: FAIL (shell no-ops).

- [ ] **Step 3: Implement** the full job:

```php
<?php

namespace App\Jobs\PreAccount;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\SourceGeneratorRegistry;
use App\Services\PreAccount\SourceGenerationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Populates a provisional user's site from its source (scrape/Places) on the
// scraping lane — a ManyChat marketing blast must never starve user-facing
// notification/cache queues (JOB-103 precedent). tries=1: a re-run re-bills the
// Apify scrape; failures surface as build_state='failed' (prunable, retryable
// via the dedupe re-serve path).
class GeneratePreAccountSiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify up to 110s + media mirroring + Places headroom. Stays under the
    // redis_scraping connection's retry_after=660 (HorizonQueueCoverageTest).
    public int $timeout = 300;

    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [30];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $buildId,
        public readonly bool $publish = false,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->buildId;
    }

    public function handle(SourceGeneratorRegistry $registry): void
    {
        $build = PreAccountBuild::find($this->buildId);
        if (! $build || $build->claimed_at !== null || $build->build_state === PreAccountBuild::STATE_READY) {
            return;
        }

        $user = $build->user;
        $site = $user?->site;
        if (! $user || ! $site) {
            $build->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);

            return;
        }

        $build->update(['build_state' => PreAccountBuild::STATE_BUILDING]);

        try {
            $registry->for($build->source_type)->generate($user, $site, $build->source_ref);
        } catch (SourceGenerationException $e) {
            $build->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode]);
            Log::info('pre_account.build_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

            return;
        } catch (Throwable $e) {
            $build->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);
            report($e);

            return;
        }

        $build->update(['build_state' => PreAccountBuild::STATE_READY]);

        // Staff marketing builds go live immediately; the KV re-sync writes the
        // routing entry (with unclaimed TTL — see SyncSubdomainToKvJob) since
        // SiteObserver only auto-dispatches KV on create/subdomain-change.
        if ($this->publish) {
            $site->update(['is_published' => true]);
            SyncSubdomainToKvJob::dispatch($user->id);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        PreAccountBuild::query()->whereKey($this->buildId)
            ->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);
    }
}
```

- [ ] **Step 4: Run** tests (PASS) + `./vendor/bin/pest tests/Feature/Queue/JobHygienePolicyTest.php tests/Unit/Jobs/HorizonQueueCoverageTest.php` — Expected: green (timeout 300 < scraping retry_after 660 and < supervisor timeout 660).

- [ ] **Step 5: Commit** — `git commit -m "feat(pre-account): GeneratePreAccountSiteJob"`

### Task 11: Public endpoints — build + poll

**Files:**
- Create: `app/Http/Controllers/Api/PublicSite/PreAccountBuildController.php`, `app/Http/Requests/Api/PublicSite/CreatePreAccountBuildRequest.php`, `app/Http/Resources/PreAccountBuildStatusResource.php`
- Modify: `routes/api/publicSite.php`, `app/Providers/AppServiceProvider.php` (rate limiter)
- Test: `tests/Feature/PreAccount/PublicBuildEndpointsTest.php`

**Interfaces:**
- Produces: `POST /api/public/signup/build` → 202 (new) / 200 (re-served) with `PreAccountBuildStatusResource`; `GET /api/public/signup/builds/{build}` → 200 resource or 404. Resource shape: `{ build_id, build_state, account_type, subdomain?, site_url?, failure_code? }` (`subdomain`/`site_url` only when `ready`).

- [ ] **Step 1: Write the failing feature tests**

```php
<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    Queue::fake();
});

it('accepts a valid signup build and returns 202 with a build id', function () {
    $res = $this->postJson('/api/public/signup/build', [
        'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => '@JaneDoe',
    ]);

    $res->assertStatus(202)->assertJsonStructure(['build_id', 'build_state']);
    Queue::assertPushed(GeneratePreAccountSiteJob::class);
});

it('re-serves an existing live build with 200 and its original account_type', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe'])->assertStatus(202);

    $res = $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'JaneDoe']);
    $res->assertStatus(200)->assertJsonPath('account_type', 'partna');
});

it('rejects a bad pairing with 422', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'google_business', 'source_ref' => 'ChIJx', 'source_name' => 'Cafe'])
        ->assertStatus(422)->assertJsonPath('code', 'SOURCE_PAIRING_INVALID');
});

it('requires source_name for google_business builds', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'business', 'source_type' => 'google_business', 'source_ref' => 'ChIJx'])
        ->assertStatus(422);
});

it('403s with WAITLIST_ONLY when the waitlist gate is on (moved from bootstrap)', function () {
    config(['partna.waitlist.enabled' => true]);
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe'])
        ->assertStatus(403)->assertJsonPath('code', 'WAITLIST_ONLY');
});

it('polls a build through its lifecycle and exposes subdomain only when ready', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe']);
    $build = PreAccountBuild::firstOrFail();

    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()->assertJsonPath('build_state', 'pending')->assertJsonMissingPath('subdomain');

    $build->update(['build_state' => PreAccountBuild::STATE_READY]);
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()->assertJsonPath('subdomain', $build->user->site->subdomain);
});

it('404s an unknown build id (public enumeration-safe)', function () {
    $this->getJson('/api/public/signup/builds/'.\Illuminate\Support\Str::uuid())->assertStatus(404);
});
```

- [ ] **Step 2: Run** — Expected: FAIL (404 route).

- [ ] **Step 3: Implement**

Rate limiter in `AppServiceProvider::configureRateLimiting()` (copy the dual-key style of the `waitlist` limiter, CF-Connecting-IP keyed):

```php
        RateLimiter::for('pre-account-build', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }
            $ip = $request->header('CF-Connecting-IP') ?: $request->ip();

            // Scraping is expensive (Apify-billed): tight per-minute + hourly ceiling.
            return [
                Limit::perMinute(3)->by('pab:m:'.$ip),
                Limit::perHour(10)->by('pab:h:'.$ip),
            ];
        });
```

`CreatePreAccountBuildRequest`:

```php
<?php

namespace App\Http\Requests\Api\PublicSite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePreAccountBuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // unauthenticated by design; abuse handled by throttle + IP cap
    }

    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', Rule::in(array_keys(config('partna.pre_account.sources', [])))],
            'source_type' => ['required', 'string', Rule::in(array_keys(config('partna.pre_account.generators', [])))],
            'source_ref' => ['required', 'string', 'max:300'],
            // F1: a GBP place_id is opaque — the picker-known business name seeds
            // the subdomain/handle/display name.
            'source_name' => ['nullable', 'string', 'max:120', 'required_if:source_type,google_business'],
        ];
    }
}
```

`PreAccountBuildStatusResource`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PreAccountBuildStatusResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $ready = $this->build_state === \App\Models\Core\User\PreAccountBuild::STATE_READY;
        $subdomain = $ready ? $this->user?->site?->subdomain : null;

        // No scraped content leaks here (spec §8) — content only ever appears via
        // the public site payload once the build is ready.
        return array_filter([
            'build_id' => $this->id,
            'build_state' => $this->build_state,
            'account_type' => $this->user?->account_type?->value,
            'subdomain' => $subdomain,
            'site_url' => $subdomain ? 'https://'.$subdomain.'.partna.au' : null,
            'failure_code' => $this->failure_code,
        ], fn ($v) => $v !== null);
    }
}
```

(Extend whatever base the sibling resources extend — verify `ApiResource` exists at `app/Http/Resources/ApiResource.php`.)

`PreAccountBuildController`:

```php
<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\CreatePreAccountBuildRequest;
use App\Http\Resources\PreAccountBuildStatusResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\JsonResponse;

class PreAccountBuildController extends ApiController
{
    public function __construct(private readonly PreAccountBuildService $builds) {}

    // POST /api/public/signup/build — unauthenticated, heavily throttled.
    public function store(CreatePreAccountBuildRequest $request): JsonResponse
    {
        // Waitlist gate relocated from the retired bootstrap create branch (F5).
        if ((bool) config('partna.waitlist.enabled', false)) {
            return $this->error('New account creation is currently waitlist-only.', 403, ['code' => 'WAITLIST_ONLY']);
        }

        $data = $request->validated();
        $ip = $request->header('CF-Connecting-IP') ?: $request->ip();

        try {
            $result = $this->builds->requestBuild(
                accountType: $data['account_type'],
                sourceType: $data['source_type'],
                rawSourceRef: $data['source_ref'],
                sourceName: $data['source_name'] ?? null,
                ipHash: hash('sha256', (string) $ip),
            );
        } catch (PreAccountBuildException $e) {
            $status = $e->errorCode === PreAccountBuildException::IP_BUILD_CAP ? 429 : 422;

            return $this->error($e->getMessage(), $status, ['code' => $e->errorCode]);
        }

        $result['build']->loadMissing('user.site');

        return $this->success(
            (new PreAccountBuildStatusResource($result['build']))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }

    // GET /api/public/signup/builds/{build} — opaque-UUID poll; 404-not-403 on
    // anything unknown (public enumeration standard).
    public function show(PreAccountBuild $build): JsonResponse
    {
        $build->loadMissing('user.site');

        return $this->success((new PreAccountBuildStatusResource($build))->resolve());
    }
}
```

Routes — add to `routes/api/publicSite.php` inside the existing public group (verify the group's prefix produces `/api/public/...`; mirror how sibling public routes are declared):

```php
Route::middleware('throttle:pre-account-build')
    ->post('/signup/build', [PreAccountBuildController::class, 'store']);
Route::middleware('throttle:public-site')
    ->get('/signup/builds/{build}', [PreAccountBuildController::class, 'show'])
    ->whereUuid('build');
```

(Route-model binding on `PreAccountBuild` must 404 cleanly — confirm `ApiController`/handler renders ModelNotFound as 404 JSON like sibling public routes.)

- [ ] **Step 4: Run** tests (PASS) + full suite. **Step 5: Commit** — `git commit -m "feat(pre-account): public build + poll endpoints"`

---

# Phase 3 — Claim flow

### Task 12: `SignupSideEffects` extraction + `ClaimSiteService`

**Files:**
- Create: `app/Services/User/SignupSideEffects.php`, `app/Services/PreAccount/ClaimSiteService.php`
- Modify: `app/Services/User/UserBootstrapService.php` (delegate `ensureSidestUpdatesSubscription` + `createWelcomeNotification` to the new class)
- Test: `tests/Feature/PreAccount/ClaimSiteServiceTest.php`

**Interfaces:**
- Produces: `SignupSideEffects::ensureSidestUpdatesSubscription(?string $email): void` and `::createWelcomeNotification(User $professional): void` — bodies moved VERBATIM from `UserBootstrapService` (lines 182-230), same idempotent `insertOrIgnore` semantics.
- Produces: `ClaimSiteService::claim(string $uid, string $verifiedEmail, string $subdomain): array{professional: User, site: Site}`; throws `RuntimeException` with message ∈ `CLAIM_NOT_FOUND | ALREADY_CLAIMED | BUILD_NOT_READY | ACCOUNT_EXISTS | EMAIL_ALREADY_REGISTERED` (mirroring the bootstrap service's string-discriminator convention).

- [ ] **Step 1: Extract `SignupSideEffects`** (move the two private methods; `UserBootstrapService` calls the injected service). Run full suite — green. Commit: `git commit -m "refactor(pre-account): extract SignupSideEffects"`

- [ ] **Step 2: Write the failing claim tests**

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEmailSubscriptionsTable();  // verify helper names in tests/Pest.php
    setupNotificationsTable();
});

function makeReadyBuild(string $subdomain = 'janedoe'): array
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => $subdomain, 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['build_state' => PreAccountBuild::STATE_READY]);
    $build->user()->associate($user);
    $build->save();

    return [$user, $site, $build];
}

it('claims: binds auth + email, activates, stamps claimed_at, runs side effects', function () {
    [$user, $site, $build] = makeReadyBuild();

    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $fresh = $user->fresh();
    expect($result['professional']->id)->toBe($user->id)
        ->and($fresh->auth_user_id)->toBe('auth-uid-1')
        ->and($fresh->primary_email)->toBe('jane@example.com')
        ->and($fresh->status)->toBe('active')
        ->and($build->fresh()->claimed_at)->not->toBeNull();
});

it('is idempotent for the rightful claimer (double-tap returns success, not 409)', function () {
    makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $again = $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');
    expect($again['professional']->auth_user_id)->toBe('auth-uid-1');
});

it('first-come wins: a second claimer gets ALREADY_CLAIMED', function () {
    makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $svc->claim('auth-uid-2', 'mallory@example.com', 'janedoe');
})->throws(RuntimeException::class, 'ALREADY_CLAIMED');

it('rejects a not-ready build', function () {
    [, , $build] = makeReadyBuild();
    $build->update(['build_state' => PreAccountBuild::STATE_BUILDING]);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'BUILD_NOT_READY');

it('rejects a claimer who already has an account (one account, one site)', function () {
    makeReadyBuild();
    User::factory()->create(['auth_user_id' => 'auth-uid-1']);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'ACCOUNT_EXISTS');

it('rejects an email already registered to another auth user', function () {
    makeReadyBuild();
    User::factory()->create(['auth_user_id' => 'other', 'primary_email' => 'jane@example.com']);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'EMAIL_ALREADY_REGISTERED');

it('404s an unknown subdomain', function () {
    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'ghost');
})->throws(RuntimeException::class, 'CLAIM_NOT_FOUND');
```

- [ ] **Step 3: Run** — Expected: FAIL. **Step 4: Implement**

```php
<?php

namespace App\Services\PreAccount;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Cache\UserCacheService;
use App\Services\User\EmailReuseGuard;
use App\Services\User\SignupSideEffects;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// First-come claim: binds a Supabase auth user (email OTP JWT) to a provisional
// unclaimed user by subdomain. Mirrors UserBootstrapService's discipline: pgsql-
// pinned transaction, lockForUpdate on the user row, savepoint-wrapped save with
// a 23505 → EMAIL_ALREADY_REGISTERED backstop (LIFE-101 pattern).
class ClaimSiteService
{
    public function __construct(
        private readonly EmailReuseGuard $emailGuard,
        private readonly SignupSideEffects $sideEffects,
        private readonly UserCacheService $userCache,
        private readonly SiteCacheService $siteCache,
    ) {}

    /**
     * @return array{professional: User, site: Site}
     *
     * @throws RuntimeException CLAIM_NOT_FOUND|ALREADY_CLAIMED|BUILD_NOT_READY|ACCOUNT_EXISTS|EMAIL_ALREADY_REGISTERED
     */
    public function claim(string $uid, string $verifiedEmail, string $subdomain): array
    {
        $result = DB::connection('pgsql')->transaction(function () use ($uid, $verifiedEmail, $subdomain) {
            $site = Site::query()->whereRaw('lower(subdomain) = ?', [strtolower(trim($subdomain))])->first();
            if (! $site) {
                throw new RuntimeException('CLAIM_NOT_FOUND');
            }

            $professional = User::query()->whereKey($site->user_id)->lockForUpdate()->first();
            if (! $professional) {
                throw new RuntimeException('CLAIM_NOT_FOUND');
            }

            // Idempotency FIRST: a double-tap / network retry by the rightful new
            // owner must return success, never 409 (spec §5.2).
            if ($professional->auth_user_id === $uid) {
                return ['professional' => $professional->fresh(), 'site' => $site->fresh()];
            }

            if (! $professional->isUnclaimed() || $professional->auth_user_id !== null) {
                throw new RuntimeException('ALREADY_CLAIMED');
            }

            $build = PreAccountBuild::query()->where('user_id', $professional->id)->lockForUpdate()->first();
            if (! $build || $build->build_state !== PreAccountBuild::STATE_READY) {
                throw new RuntimeException('BUILD_NOT_READY');
            }

            // One account, one site: the claimer must not already own a row.
            if (User::query()->where('auth_user_id', $uid)->exists()) {
                throw new RuntimeException('ACCOUNT_EXISTS');
            }

            if ($this->emailGuard->isClaimedByAnotherAuthUser($verifiedEmail, $uid)) {
                throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
            }

            $professional->auth_user_id = $uid; // not fillable — direct assignment
            $professional->primary_email = strtolower(trim($verifiedEmail));
            $professional->status = 'active';

            try {
                // Savepoint: a 23505 rollback must not poison the outer transaction
                // (partial unique index race on users_email_unique).
                DB::connection('pgsql')->transaction(fn () => $professional->save());
            } catch (UniqueConstraintViolationException $e) {
                if ($this->emailGuard->isClaimedByAnotherAuthUser($verifiedEmail, $uid)) {
                    throw new RuntimeException('EMAIL_ALREADY_REGISTERED', 0, $e);
                }
                throw $e;
            }

            $build->update(['claimed_at' => now()]);

            // Claim-time side effects moved from the retired bootstrap create branch.
            $this->sideEffects->ensureSidestUpdatesSubscription($professional->primary_email);
            $this->sideEffects->createWelcomeNotification($professional);

            return ['professional' => $professional->fresh(), 'site' => $site->fresh()];
        });

        // Post-commit: bust caches and re-sync KV (status active → permanent
        // entry, clearing the unclaimed TTL). SyncSubdomainToKvJob remains the
        // single KV writer — this only dispatches it.
        $this->userCache->invalidateUser($result['professional']);
        $this->siteCache->invalidateSite($result['site']);
        SyncSubdomainToKvJob::dispatch((string) $result['professional']->id);

        return $result;
    }
}
```

(Verify `UserCacheService::invalidateUser` + `SiteCacheService::invalidateSite` signatures before wiring.)

- [ ] **Step 5: Run** tests (PASS) + full suite. **Step 6: Commit** — `git commit -m "feat(pre-account): ClaimSiteService"`

### Task 13: `POST /api/claim` endpoint

**Files:**
- Create: `app/Http/Controllers/Api/PublicSite/ClaimController.php`, `app/Http/Requests/Api/PublicSite/ClaimSiteRequest.php`
- Modify: `routes/api.php` (beside `/bootstrap`), `app/Providers/AppServiceProvider.php` (claim limiter)
- Test: `tests/Feature/PreAccount/ClaimEndpointTest.php`

**Interfaces:**
- Produces: `POST /api/claim` (middleware `['supabase.jwt', 'throttle:claim']`), body `{ subdomain }`, success = the bootstrap-shaped `{ professional: UserDashboardResource, site: <raw Site> }` payload; errors: 404 `CLAIM_NOT_FOUND`, 409 `ALREADY_CLAIMED|BUILD_NOT_READY|ACCOUNT_EXISTS|EMAIL_ALREADY_REGISTERED`, 422 `EMAIL_VERIFICATION_REQUIRED`.

- [ ] **Step 1: Write the failing feature tests** — arrange with the same JWT-faking helper existing bootstrap feature tests use (`grep -rln "supabase_uid" tests/Feature | head -5`, copy the actingAs/withToken arrange):

```php
it('claims a ready build end-to-end and returns the bootstrap-shaped payload', function () {
    [, $site] = /* makeReadyBuild() as in Task 12 */;
    actingAsSupabaseUser('auth-uid-1', 'jane@example.com'); // repo's JWT test helper

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertOk()
        ->assertJsonStructure(['professional' => ['id', 'display_name', 'status'], 'site' => ['id', 'subdomain']])
        ->assertJsonPath('professional.status', 'active');
});

it('409s the second claimer', function () { /* claim once, then as auth-uid-2 expect 409 + code ALREADY_CLAIMED */ });
it('422s a token with no verified email claim', function () { /* JWT without email claim → EMAIL_VERIFICATION_REQUIRED */ });
it('404s an unknown subdomain', function () { /* → 404, no existence leak */ });
```

(Write these four fully once the JWT helper name is confirmed — the assertions above are the contract.)

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement**

Limiter:

```php
        RateLimiter::for('claim', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }
            $uid = (string) $request->attributes->get('supabase_uid');
            if ($uid === '') {
                throw new RuntimeException('claim limiter requires supabase_uid — check middleware order.');
            }

            return Limit::perMinute(5)->by('claim:'.$uid);
        });
```

`ClaimSiteRequest`: `['subdomain' => ['required', 'string', 'max:63']]`, `authorize(): true`.

`ClaimController`:

```php
<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\ClaimSiteRequest;
use App\Http\Resources\UserDashboardResource;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ClaimController extends ApiController
{
    public function __construct(private readonly ClaimSiteService $claims) {}

    // POST /api/claim — JWT whose sub has no core.users row yet claims an
    // unclaimed site by subdomain (first-come). Same OV-A hardening as bootstrap:
    // the email is ONLY ever read from the verified JWT claim, never the body.
    public function store(ClaimSiteRequest $request): JsonResponse
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        $claims = $request->attributes->get('supabase_claims');
        $verifiedEmail = is_array($claims) ? trim((string) ($claims['email'] ?? '')) : '';
        if ($verifiedEmail === '') {
            return $this->error('A verified email is required to claim your site.', 422, ['code' => 'EMAIL_VERIFICATION_REQUIRED']);
        }

        try {
            $result = $this->claims->claim($uid, $verifiedEmail, $request->validated()['subdomain']);
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'CLAIM_NOT_FOUND' => $this->error('No site found for that address.', 404, ['code' => 'CLAIM_NOT_FOUND']),
                'ALREADY_CLAIMED' => $this->error('This site has already been claimed.', 409, ['code' => 'ALREADY_CLAIMED']),
                'BUILD_NOT_READY' => $this->error('This site is still being built.', 409, ['code' => 'BUILD_NOT_READY']),
                'ACCOUNT_EXISTS' => $this->error('Your account already has a site.', 409, ['code' => 'ACCOUNT_EXISTS']),
                'EMAIL_ALREADY_REGISTERED' => $this->error('This email is already registered.', 409, ['code' => 'EMAIL_ALREADY_REGISTERED']),
                default => throw $e,
            };
        }

        // Bootstrap-shaped payload so the frontend lands straight in the dashboard
        // (professional via resource; site raw — byte-compatible with /bootstrap).
        return $this->success([
            'professional' => new UserDashboardResource($result['professional']),
            'site' => $result['site'],
        ]);
    }
}
```

Route (`routes/api.php`, beside the bootstrap line, same comment style):

```php
Route::middleware(['supabase.jwt', 'throttle:claim'])->post('/claim', [ClaimController::class, 'store']);
```

- [ ] **Step 4: Run** tests (PASS) + full suite. **Step 5: Commit** — `git commit -m "feat(pre-account): POST /api/claim"`

### Task 14: Bootstrap create-branch retirement

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- Test: `tests/Feature/PreAccount/BootstrapRetirementTest.php` + existing bootstrap tests

**Interfaces:**
- Produces: `POST /api/bootstrap` returns **410 `{code: 'SIGNUP_MOVED'}`** when the caller's `sub` has no `core.users` row; the update/refresh path is byte-identical for existing users. Waitlist/invite gating blocks in the controller become dead for creation and are REMOVED (the waitlist gate now lives on the build endpoint — Task 11; F5/F6).

- [ ] **Step 1: Write the failing tests**

```php
it('410s a JWT with no existing user row, pointing at the new flow', function () {
    actingAsSupabaseUser('brand-new-uid', 'new@example.com');

    $this->postJson('/api/bootstrap', ['display_name' => 'New Person', 'primary_email' => 'new@example.com'])
        ->assertStatus(410)->assertJsonPath('code', 'SIGNUP_MOVED');
});

it('still refreshes an existing user (update path untouched)', function () {
    // arrange an existing active user bound to the JWT sub, then POST /api/bootstrap
    // and assert 200 + the professional/site payload — copy the arrange from the
    // existing bootstrap feature test suite.
});
```

- [ ] **Step 2: Run** — FAIL (currently creates). **Step 3: Implement** — in `BootstrapController::bootstrap()`, immediately after the verified-email check (line ~54), add:

```php
        // Pre-Account Sites: signup is site-first now. The create branch is
        // retired — a sub with no row builds a site (POST /api/public/signup/build)
        // and claims it (POST /api/claim). The update path below survives as the
        // idempotent profile-refresh existing users rely on.
        if (! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'Signup now starts from your site. Build it first, then claim it.',
                410,
                ['code' => 'SIGNUP_MOVED']
            );
        }
```

Then delete the now-dead creation-gating blocks: the invite-token block (lines ~56-79), the `WAITLIST_ONLY` block (~81-87), and the individual-waitlist divert (~89-127) — all three only ever fired for `! hasExistingProfessional($uid)` callers, which now 410 first. Keep `markSignedUp` (harmless on refresh) only if its removal breaks tests; otherwise remove it too and delete unused imports.

- [ ] **Step 4: Update existing bootstrap tests.** Creation-path tests (creates-a-user, waitlist-gating, invite tests) now assert 410/moved semantics or move to build-endpoint coverage. Do NOT weaken update-path tests. Run `composer test` — Expected: green after updates.

- [ ] **Step 5: Commit** — `git commit -m "feat(pre-account): retire bootstrap create branch (410 SIGNUP_MOVED)"`

---

# Phase 4 — KV routability/TTL + expiry prune

### Task 15: `SyncSubdomainToKvJob` — unclaimed routability + TTL

**Files:**
- Modify: `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` (handle() gate + individual-entry put)
- Test: `tests/Unit/Jobs/SyncSubdomainToKvJobTest.php` (extend the existing test file — `grep -rln "SyncSubdomainToKvJob" tests/`)

**Interfaces:**
- Produces: unclaimed owners route with `expirationTtl` aligned to `pre_account_builds.expires_at` (min 60s CF floor); expired-build owners retire; active owners unchanged (null TTL); claim's re-dispatch (Task 12) therefore rewrites the entry permanent.

- [ ] **Step 1: Write the failing tests**

```php
it('writes a TTL-bearing individual entry for an unclaimed owner with a live build', function () {
    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldReceive('put')->once()->withArgs(function (string $key, array $value, ?int $ttl) {
        return $key === 'janedoe' && $value === ['type' => 'individual']
            && $ttl !== null && $ttl > 60 && $ttl <= 30 * 86400;
    });
    app()->instance(CloudflareKvService::class, $kv);

    $user = User::factory()->create(['status' => 'unclaimed', 'handle' => 'janedoe', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['expires_at' => now()->addDays(30)]);
    $build->user()->associate($user);
    $build->save();

    (new SyncSubdomainToKvJob($user->id))->handle(app(CloudflareKvService::class));
});

it('retires an unclaimed owner whose build has expired', function () {
    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldReceive('delete')->atLeast()->once();
    $kv->shouldNotReceive('put');
    app()->instance(CloudflareKvService::class, $kv);

    $user = User::factory()->create(['status' => 'unclaimed', 'handle' => 'janedoe']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['expires_at' => now()->subDay()]);
    $build->user()->associate($user);
    $build->save();

    (new SyncSubdomainToKvJob($user->id))->handle(app(CloudflareKvService::class));
});

it('still writes active owners with no TTL', function () {
    // existing-behavior pin: active user → put($handle, ['type' => 'individual'], null)
});
```

(Match the existing test file's arrange/mocking style — the job's `handle()` signature may take the KV service plus other collaborators; mirror how current tests invoke it.)

- [ ] **Step 2: Run** — FAIL (unclaimed currently retires). **Step 3: Implement** — in `handle()`:

Replace the retire gate (lines ~94-98):

```php
        // Routable = active OR unclaimed (pre-account build; publish is a separate
        // knob — an unpublished site 404s at the payload layer, same as today).
        if (! $pro || $pro->trashed() || ! $pro->handle || ! ($pro->isActive() || $pro->isUnclaimed())) {
            $this->retire($kv);

            return;
        }
```

Before the individual `put` (line ~121):

```php
        // KV TTL defense-in-depth (spec §4): an unclaimed owner's entry expires at
        // the edge in lockstep with the build, so a failed prune can't leave a
        // routable orphan. Claiming re-dispatches this job with status=active,
        // rewriting the entry permanent (null TTL).
        $ttl = null;
        if ($pro->isUnclaimed()) {
            $expiresAt = $pro->preAccountBuild?->expires_at;
            if (! $expiresAt || now()->gte($expiresAt)) {
                $this->retire($kv); // expired (or buildless) unclaimed — treat as gone

                return;
            }
            // Cloudflare KV enforces a 60s minimum TTL.
            $ttl = max(60, (int) now()->diffInSeconds($expiresAt, false));
        }

        $kv->put($current, ['type' => 'individual'], $ttl);
```

Also gate the custom-domain branch: unclaimed users cannot have custom domains — leave the existing `custom_domain_status === 'active'` condition as-is (it will simply never match) and add no unclaimed-specific handling.

- [ ] **Step 4: Run** the job's full test file + full suite — green. **Step 5: Commit** — `git commit -m "feat(pre-account): KV routes unclaimed owners with build-aligned TTL"`

### Task 16: `builds:prune-expired` command

**Files:**
- Create: `app/Console/Commands/PruneExpiredPreAccountBuilds.php`
- Modify: `app/Services/User/AccountDeletionService.php` (make `purgeMediaArtifacts` public — verify exact name/visibility first), `routes/console.php`
- Test: `tests/Feature/PreAccount/PruneExpiredBuildsTest.php`

**Interfaces:**
- Produces: daily command hard-deleting expired unclaimed builds (and failed builds older than `failed_prune_hours`), teardown-before-cascade ordering mirroring `AccountDeletionService::purge()` (no Supabase auth step — `auth_user_id` is NULL), `FOR UPDATE SKIP LOCKED` so a mid-claim row is skipped.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupIntegrationConnectionsTable();
});

function makeExpiredBuild(): array
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'handle' => 'stale']);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'stale']);
    $build = PreAccountBuild::factory()->make(['expires_at' => now()->subDay()]);
    $build->user()->associate($user);
    $build->save();

    return [$user, $site, $build];
}

it('hard-deletes an expired unclaimed build and its user/site', function () {
    [$user, $site] = makeExpiredBuild();

    $this->artisan('builds:prune-expired')->assertSuccessful();

    expect(User::withTrashed()->find($user->id))->toBeNull()
        ->and(Site::query()->find($site->id))->toBeNull()   // SQLite: assert via cascade OR explicit delete — see impl note
        ->and(PreAccountBuild::query()->count())->toBe(0);
});

it('prunes failed builds older than the failed window, keeps fresh ones', function () {
    [$u1, , $b1] = makeExpiredBuild();
    $b1->update(['expires_at' => now()->addDays(20), 'build_state' => PreAccountBuild::STATE_FAILED, 'updated_at' => now()->subHours(30)]);

    $u2 = User::factory()->create(['status' => 'unclaimed', 'handle' => 'fresh']);
    Site::factory()->create(['user_id' => $u2->id, 'subdomain' => 'fresh']);
    $b2 = PreAccountBuild::factory()->make(['expires_at' => now()->addDays(29)]);
    $b2->user()->associate($u2);
    $b2->save();

    $this->artisan('builds:prune-expired')->assertSuccessful();

    expect(User::query()->find($u1->id))->toBeNull()
        ->and(User::query()->find($u2->id))->not->toBeNull();
});

it('never touches claimed builds', function () {
    [, , $build] = makeExpiredBuild();
    $build->update(['claimed_at' => now()]);

    $this->artisan('builds:prune-expired')->assertSuccessful();

    expect(PreAccountBuild::query()->count())->toBe(1);
});

it('supports --dry-run', function () {
    makeExpiredBuild();
    $this->artisan('builds:prune-expired --dry-run')->assertSuccessful();
    expect(PreAccountBuild::query()->count())->toBe(1);
});
```

Implementation note for the first test: SQLite in tests does NOT run Postgres FK cascades — the command must therefore delete via Eloquent in explicit order (which is also what fires the observers we need). Do not rely on DB cascade for anything the command itself must guarantee.

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\User\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Daily teardown of expired (never-claimed) pre-account builds and stale failed
// builds. Ordering mirrors AccountDeletionService::purge()'s capture-before-
// cascade discipline, minus the Supabase-auth step (provisional users have no
// auth user). The claim/prune race is settled by FOR UPDATE SKIP LOCKED: a user
// row locked by an in-flight claim transaction is skipped this run; a committed
// claim flips the row out of the predicate entirely (claimed_at set).
class PruneExpiredPreAccountBuilds extends Command
{
    protected $signature = 'builds:prune-expired {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete expired unclaimed pre-account builds (site teardown before row cascade).';

    public function handle(SiteCacheService $siteCache, AccountDeletionService $deletion): int
    {
        $cutoff = now();
        $failedCutoff = now()->subHours((int) config('partna.pre_account.failed_prune_hours', 24));

        $candidates = PreAccountBuild::live()
            ->where(fn ($q) => $q
                ->where('expires_at', '<', $cutoff)
                ->orWhere(fn ($qq) => $qq->where('build_state', PreAccountBuild::STATE_FAILED)->where('updated_at', '<', $failedCutoff)))
            ->pluck('id');

        if ($this->option('dry-run')) {
            $this->info("Would prune {$candidates->count()} build(s).");

            return self::SUCCESS;
        }

        $pruned = 0;
        foreach ($candidates as $buildId) {
            $pruned += (int) DB::connection('pgsql')->transaction(function () use ($buildId, $siteCache, $deletion) {
                $build = PreAccountBuild::query()->whereKey($buildId)->first();
                if (! $build || $build->claimed_at !== null) {
                    return false; // claimed since candidate selection
                }

                // SKIP LOCKED: a claim transaction holds this row's lock — skip,
                // re-evaluate next run. (SQLite ignores the lock clause; the
                // Postgres behavior is the contract.)
                $user = User::query()->whereKey($build->user_id)
                    ->lock('for update skip locked')->first();
                if (! $user) {
                    return false;
                }

                $site = $user->site;
                $wasPublished = (bool) $site?->is_published;
                $handle = $user->handle;

                // 1. Connection rows via Eloquent (NOT cascade) so the observer
                //    reclaims mirrored R2 folders (DeleteMirroredMediaJob).
                IntegrationConnection::query()->where('user_id', $user->id)->get()
                    ->each->delete();

                // 2. R2 artifacts for any site_media before the row cascade
                //    (DB cascades never touch storage) — reuses the purge seam.
                $deletion->purgeMediaArtifacts($user);

                // 3. App-cache bust (direct call — teardown must not depend on
                //    observer ordering) + edge purge for a site that was live.
                if ($site) {
                    $siteCache->invalidateSite($site);
                    if ($wasPublished && $handle) {
                        CloudflareCachePurgeJob::dispatch($handle)->afterCommit();
                    }
                }

                // 4. Hard delete. UserObserver::deleted dispatches the KV retire
                //    with the captured handle (single-writer path). FK cascade
                //    takes the build row, site, design kit, blocks, media rows on
                //    Postgres; explicit deletes above cover what needs observers.
                $user->forceDelete();

                return true;
            });
        }

        Log::info('pre_account.prune.completed', ['candidates' => $candidates->count(), 'pruned' => $pruned]);
        $this->info("Pruned {$pruned} of {$candidates->count()} candidate build(s).");

        return self::SUCCESS;
    }
}
```

Implementation notes: (a) verify `AccountDeletionService::purgeMediaArtifacts()` exact name/signature (`grep -n "purgeMediaArtifacts" app/Services/User/AccountDeletionService.php`) and change `private` → `public` with a docblock noting the prune command as a second caller; (b) verify `CloudflareCachePurgeJob::dispatch` signature (`(string $handle, ?string $customDomain = null)` per the purge service report); (c) on SQLite the FK cascade doesn't run — if the first test's `Site::find` assertion fails for that reason, have the command delete the site via `$site->delete()` before `forceDelete()` ONLY if that matches observer expectations on Postgres too; otherwise assert site deletion in a Postgres-verified way (drift rule) and adjust the test to assert user + build deletion plus explicit-side-effect calls.

Schedule (`routes/console.php`, beside the alias-prune entry, same options):

```php
Schedule::command('builds:prune-expired')
    ->dailyAt('03:40')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-expired-pre-account-builds'));
```

- [ ] **Step 4: Run** tests (PASS) + full suite. **Step 5: Commit** — `git commit -m "feat(pre-account): builds:prune-expired teardown command"`

---

# Phase 5 — Staff surface, CI wiring, docs

### Task 17: `POST /api/staff/builds`

**Files:**
- Create: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php`, `app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php`
- Modify: `routes/api/staff.php` (regular staff group)
- Test: `tests/Feature/PreAccount/StaffBuildEndpointTest.php`

**Interfaces:**
- Produces: `POST /api/staff/builds` — body `{ account_type, source_type, source_ref, source_name?, publish?: bool=true, expires_days?: int }` → 202/200 `PreAccountBuildStatusResource`. Auth: standard staff middleware stack (already includes `require.aal2`), plus `authorizeForUser($staff, 'staffCreate', PreAccountBuild::class)`.

- [ ] **Step 1: Write the failing tests** — copy the arrange (staff JWT + `partna_staff` row + AAL2 claims) from an existing staff feature test:

```php
it('lets staff trigger a published marketing build', function () {
    actingAsStaffWithAal2(); // repo's staff test helper — confirm exact name
    Queue::fake();

    $this->postJson('/api/staff/builds', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'prospect'])
        ->assertStatus(202);

    $build = PreAccountBuild::firstOrFail();
    expect($build->built_via)->toBe(PreAccountBuild::VIA_STAFF)
        ->and($build->built_by_staff_id)->not->toBeNull();
    Queue::assertPushed(GeneratePreAccountSiteJob::class, fn ($j) => $j->publish === true);
});

it('honours publish=false and expires_days', function () { /* publish:false → job publish false; expires_days:60 → expires_at ~60d */ });
it('rejects non-staff callers', function () { /* plain user JWT → 403 staff_required (middleware) */ });
it('ignores the IP cap for staff builds', function () { /* config cap 0 → staff build still 202 */ });
```

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement**

`StaffCreatePreAccountBuildRequest` — same rules as the public request plus:

```php
            'publish' => ['sometimes', 'boolean'],
            'expires_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
```

Controller:

```php
<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\StaffCreatePreAccountBuildRequest;
use App\Http\Resources\PreAccountBuildStatusResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\JsonResponse;

class StaffPreAccountBuildController extends ApiController
{
    public function __construct(private readonly PreAccountBuildService $builds) {}

    // POST /api/staff/builds — the ManyChat/marketing surface. Builds publish by
    // default (the site IS the pitch); the public endpoint never publishes pre-claim.
    public function store(StaffCreatePreAccountBuildRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $data = $request->validated();

        try {
            $result = $this->builds->requestBuild(
                accountType: $data['account_type'],
                sourceType: $data['source_type'],
                rawSourceRef: $data['source_ref'],
                sourceName: $data['source_name'] ?? null,
                ipHash: null,
                staff: $staff,
                publish: (bool) ($data['publish'] ?? true),
                expiresDays: isset($data['expires_days']) ? (int) $data['expires_days'] : null,
            );
        } catch (PreAccountBuildException $e) {
            return $this->error($e->getMessage(), 422, ['code' => $e->errorCode]);
        }

        $result['build']->loadMissing('user.site');

        return $this->success(
            (new PreAccountBuildStatusResource($result['build']))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }
}
```

Route in the REGULAR staff group of `routes/api/staff.php`:

```php
    Route::post('/builds', [StaffPreAccountBuildController::class, 'store']);
```

(Class-string policy dispatch: `authorizeForUser($staff, 'staffCreate', PreAccountBuild::class)` — the Gate resolves `PreAccountBuildPolicy` from the class name; confirm against how existing class-level abilities are called in staff controllers, or pass `[PreAccountBuild::class]` if the repo's convention requires the array form.)

- [ ] **Step 4: Run** tests (PASS) + full suite. **Step 5: Commit** — `git commit -m "feat(pre-account): staff/ManyChat build endpoint"`

### Task 18: Staff visibility of the marketing pipeline

**Files:**
- Modify: `app/Http/Resources/UserStaffResource.php`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` (eager-load only)
- Test: `tests/Feature/PreAccount/StaffUnclaimedVisibilityTest.php`

**Interfaces:**
- Produces: staff user detail includes `pre_account_build: { source_type, source_ref, built_via, build_state, failure_code, expires_at, claimed_at }` when the relation is loaded; `?status=unclaimed` filtering on the staff index already works (arbitrary status filter — verified) and gets a pin test.

- [ ] **Step 1: Write the failing tests** (index filter pin + detail block presence for an unclaimed user; absence-of-block for a normal user). **Step 2: Run** — FAIL on the detail block.

- [ ] **Step 3: Implement** — in `UserStaffResource::toArray()`:

```php
            'pre_account_build' => $this->whenLoaded('preAccountBuild', fn () => [
                'source_type' => $this->preAccountBuild->source_type,
                'source_ref' => $this->preAccountBuild->source_ref,
                'built_via' => $this->preAccountBuild->built_via,
                'build_state' => $this->preAccountBuild->build_state,
                'failure_code' => $this->preAccountBuild->failure_code,
                'expires_at' => $this->preAccountBuild->expires_at,
                'claimed_at' => $this->preAccountBuild->claimed_at,
            ]),
```

…and add `->with('preAccountBuild')` (or `loadMissing`) at the staff show call site that builds `UserStaffResource` (find it: `grep -n "UserStaffResource" app/Http/Controllers/Api/Staff -r`).

- [ ] **Step 4: Run** tests (PASS) + full suite. **Step 5: Commit** — `git commit -m "feat(pre-account): staff visibility of unclaimed pipeline"`

### Task 19: CI wiring, docs, final sweep

**Files:**
- Modify: `scripts/audit/audit.sh` (`codebase_chunks()`), the matching lens `.md` scope-group(s) in `scripts/audit/lenses/`, `docs/api.md`, `AI_CONTEXT.md`

- [ ] **Step 1: Audit-pipeline wiring.** Run `./vendor/bin/pest tests/Feature/Architecture/AuditPipelineIntegrityTest.php`. It will name the uncovered new directories (`app/Services/PreAccount`, `app/Jobs/PreAccount`, `tests/Feature/PreAccount`). Add each to the appropriate existing chunk in `codebase_chunks()` (services chunk / jobs chunk / tests chunk — follow the file's own grouping) AND to the corresponding lens scope-group `.md`. Re-run until green. Fix — never exempt.

- [ ] **Step 2: Docs.** In `docs/api.md`, add the four endpoints (public build, poll, claim, staff builds) with request/response shapes exactly as implemented, and update the `POST /api/bootstrap` entry (410 create-branch retirement). In `AI_CONTEXT.md`, update ONLY the signup/account-lifecycle sections this feature touches: site-first signup, provisional users (`status='unclaimed'`, null `auth_user_id`/`primary_email`), `core.pre_account_builds`, claim flow, expiry prune. Do not attempt a general de-staling of either doc.

- [ ] **Step 3: Frontend contract (handoff) —** verify the "Frontend-facing contract changes" section at the bottom of this plan still matches what shipped; amend it if any task diverged.

- [ ] **Step 4: Final verification.** `composer test` (full), `php artisan pint --dirty`, then re-run the three guard suites explicitly: PolicyCoverageTest, JobHygienePolicyTest, AuditPipelineIntegrityTest. All green.

- [ ] **Step 5: Commit** — `git commit -m "chore(pre-account): CI wiring + docs + contract"`

---

## Frontend-facing contract changes (handoff — backend ships these; frontend is a separate repo)

1. **NEW `POST /api/public/signup/build`** — `{ account_type: 'partna'|'business', source_type: 'instagram'|'google_business', source_ref, source_name? }` (`source_name` REQUIRED for `google_business` — the Places picker's business name). 202 (new) / 200 (re-served existing live build — response includes the build's original `account_type`; reflect it in the UI). Errors: 422 `SOURCE_PAIRING_INVALID|SOURCE_REF_INVALID`, 429 `IP_BUILD_CAP`, 403 `WAITLIST_ONLY`.
2. **NEW `GET /api/public/signup/builds/{build_id}`** — poll: `{ build_id, build_state: pending|building|ready|failed, account_type, subdomain?, site_url?, failure_code? }`. `subdomain`/`site_url` appear only when `ready`. Survives refresh: persist `build_id` client-side.
3. **NEW `POST /api/claim`** — Supabase email-OTP JWT required; body `{ subdomain }`. Success = the exact `/api/bootstrap`-shaped `{ professional, site }` payload → land in dashboard. Errors: 404 `CLAIM_NOT_FOUND`, 409 `ALREADY_CLAIMED|BUILD_NOT_READY|ACCOUNT_EXISTS|EMAIL_ALREADY_REGISTERED`, 422 `EMAIL_VERIFICATION_REQUIRED`.
4. **CHANGED `POST /api/bootstrap`** — returns **410 `{code:'SIGNUP_MOVED'}`** for a JWT with no existing user row. Update/refresh path for existing users unchanged. Invite-token + waitlist params on bootstrap are dead; the waitlist gate is now a 403 on the build endpoint (no invite bypass — invites retire with the create branch).
5. **NEW staff surface `POST /api/staff/builds`** (staff dashboard / ManyChat) — public-build body plus `publish` (default true) and `expires_days`.
6. Staff user detail now includes `pre_account_build{...}`; unclaimed users are filterable via the existing `?status=unclaimed` index filter.

## Self-review checklist (run after writing, before handing to Josh)

- Spec coverage: §3 migration → T1; §4 entry points/service/job/generators → T7-T11, T17; §5 claim + bootstrap → T12-T14; §6 expiry → T15-T16; §7 gating sweep → T2-T4, T18, wiring T19; §8 abuse → T7 (cap), T11 (throttle/waitlist); §9 tests → distributed per task.
- Deliberately NOT covered (spec §10): OAuth enrichment, ManyChat itself, claim tokens, legal posture, full bootstrap retirement.
- Captcha (spec §8 "hook if pressure appears") deliberately deferred — the `bot.token` middleware + `VerifyBotToken` pattern exists; adding `bot.token:build` later is a one-line route change. Noted here so it isn't read as a gap.
