# Legacy `account_type` Removal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove all four legacy `core.users.account_type` values from the codebase, fix the KV backfill command that would silently no-op at prod cutover, and add the CHECK constraint that stops the class of bug recurring.

**Architecture:** Five code tasks on branch `cleanup/account-type-legacy-2026-07-20`, plus one gated DB task. Task 1 is independent and shippable alone. Task 2 is a *discovery* step — it adds prod's CHECK constraint to the test schema to harvest an exact inventory of bad seeds, then reverts without committing. Tasks 3–5 fix what the inventory found. Task 6 re-lands the CHECK, now green, as the permanent guard. Every commit is green and bisectable.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, SQLite in-memory (tests), PostgreSQL/Supabase (prod), PHPStan, Pint.

**Spec:** `docs/superpowers/specs/2026-07-20-account-type-legacy-removal-design.md`

## Global Constraints

- **Never create Laravel migration files.** `composer test` runs `guard:no-laravel-migrations` and will fail the build. Schema changes go in `supabase/migrations/` as raw SQL.
- **Never run `git stash`.** This is a shared repo with concurrent work; stashing can wipe another session's uncommitted files.
- **Before every commit run `git diff --cached --stat`** and confirm the file count matches what the task specifies. The index may hold prior-session work.
- **Do NOT touch these `'individual'` usages — they are a different concept entirely:**
  - `worker_kv_type: 'individual'` — `app/Services/Accounts/AccountCapabilities.php:55`
  - `['type' => 'individual']` — `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:138,148`
  - the `'individual'` product bucket — `app/Models/Core/Site/ShopBrand.php`, `app/Http/Controllers/Api/Platforms/ShopController.php`, `app/Http/Resources/Platforms/ShopBrandResource.php`
  These are Cloudflare KV routing and product grouping. Replacing them breaks sitepage routing. Every sweep in this plan matches the **`'account_type' => 'individual'`** pattern specifically, never the bare string.
- **Valid account types are exactly `'partna'` and `'business'`.** Prod CHECK: `users_account_type_check`.
- Commands: `composer test` (full suite), `vendor/bin/pest <file>` (single file), `composer analyse` (PHPStan), `vendor/bin/pint <files>` (style).
- Do not run `composer test` while a subagent is also running tests.
- **The `CREATE TABLE core.users` DDL lives inside a PHP single-quoted string literal.** Any quote inserted into that SQL must be backslash-escaped for PHP (`\'partna\'`), or the string terminates early and the file dies with a `ParseError` instead of producing the intended SQLite `CHECK constraint failed`. The `perl` commands below emit `\x5c\x27` (backslash, quote) for exactly this reason — do not "simplify" them back to bare quotes. Discovered the hard way during Task 2.

---

### Task 1: Fix the KV backfill cohort

**Why this is first:** `BackfillUserKvEntries` is provisioning infrastructure, not dead code — the strip-down plan step 9 runs it after provisioning a fresh Supabase project and `SUBDOMAIN_KV` namespace, and a pilot prod cutover is the plan of record. Its filter matches zero rows, so at cutover it would populate nothing, 404 every sitepage, and exit `SUCCESS`.

The existing test passes only because it seeds `'individual'` — the one value the broken filter matches. Flipping the seed to a *legal* value is what makes the bug visible.

**Files:**
- Modify: `app/Console/Commands/BackfillUserKvEntries.php:32-35`
- Test: `tests/Feature/Console/BackfillUserKvEntriesTest.php`

**Interfaces:**
- Consumes: nothing from other tasks. Fully independent.
- Produces: nothing other tasks rely on.

- [ ] **Step 1: Rewrite the test to seed legal account types**

Replace the entire contents of `tests/Feature/Console/BackfillUserKvEntriesTest.php`:

```php
<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
});

/** Insert a user row directly; $type must be a value prod's CHECK permits. */
function backfillSeedUser(string $handle, string $type): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => $handle,
        'account_type' => $type,
        'status' => 'active',
        'primary_email' => $handle.'@x.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('dispatches SyncSubdomainToKvJob for a partna account', function () {
    Bus::fake();

    $id = backfillSeedUser('solo1', 'partna');

    $this->artisan('partna:backfill-user-kv-entries')
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertDispatched(SyncSubdomainToKvJob::class, fn ($job) => $job->userId === $id);
    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 1);
});

// The cutover cohort is mixed. A filter that silently excludes business
// accounts would leave half the sitepages unroutable after a fresh-KV
// provision, while still reporting success.
it('dispatches for business accounts too', function () {
    Bus::fake();

    backfillSeedUser('solo2', 'partna');
    backfillSeedUser('shop1', 'business');

    $this->artisan('partna:backfill-user-kv-entries')
        ->expectsOutputToContain('Target cohort: 2')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 2);
});

it('skips users with no handle', function () {
    Bus::fake();

    backfillSeedUser('solo3', 'partna');

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'handle' => null,
        'handle_lc' => null,
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'nohandle@x.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('partna:backfill-user-kv-entries')
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 1);
});

it('--dry-run reports the cohort and dispatches nothing', function () {
    Bus::fake();

    backfillSeedUser('solo4', 'partna');

    $this->artisan('partna:backfill-user-kv-entries', ['--dry-run' => true])
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/pest tests/Feature/Console/BackfillUserKvEntriesTest.php
```

Expected: FAIL. The output will contain `Target cohort: 0` where `Target cohort: 1` was expected, because the `where('account_type','individual')` filter excludes every legal account type. This failure IS the production bug.

- [ ] **Step 3: Drop the filter**

In `app/Console/Commands/BackfillUserKvEntries.php`, replace:

```php
        $query = User::query()
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->where('account_type', 'individual');
```

with:

```php
        // No account_type filter: every account renders an individual sitepage,
        // and 'individual' has not been a legal value since 20260612120000 — this
        // filter matched zero rows and made the command a silent no-op at cutover.
        // Cohort gates (moderation-hidden, unclaimed TTL, expiry) live in
        // SyncSubdomainToKvJob, the single authority. Do not duplicate them here.
        $query = User::query()
            ->whereNotNull('handle')
            ->where('handle', '!=', '');
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/pest tests/Feature/Console/BackfillUserKvEntriesTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Console/Commands/BackfillUserKvEntries.php tests/Feature/Console/BackfillUserKvEntriesTest.php
git add app/Console/Commands/BackfillUserKvEntries.php tests/Feature/Console/BackfillUserKvEntriesTest.php
git diff --cached --stat   # expect exactly 2 files
git commit -m "fix(kv): backfill command matched zero rows at cutover

The account_type='individual' filter has matched nothing since
20260612120000. On a fresh SUBDOMAIN_KV provision the command reported
success while populating no routes. Cohort gating belongs to
SyncSubdomainToKvJob; the command now selects every handled user.

The prior test passed only because it seeded 'individual' — a value the
production CHECK constraint forbids."
```

---

### Task 2: Discovery — harvest the bad-seed inventory (NO COMMIT)

**Why:** A 71-file find-and-replace is a survey, not a proof. Adding prod's CHECK to the test schema converts every bad seed into a loud failure, producing an exact inventory including anything grep missed. This task deliberately produces a red suite and **commits nothing**.

**Files:**
- Temporarily modify (then revert): the 8 files listed in Step 1
- Create: `/private/tmp/claude-501/-Users-joshuahunter-Herd-Side-Street-backend/a69266bd-3c21-4bdc-898a-263c12473b35/scratchpad/bad-seed-inventory.txt`

**Interfaces:**
- Consumes: nothing.
- Produces: `bad-seed-inventory.txt` — the authoritative list of files Tasks 3 and 4 must fix.

- [ ] **Step 1: Add the CHECK to all 8 test table definitions**

These are the only 8 of the 13 `CREATE TABLE IF NOT EXISTS core.users` definitions that declare the column. The `perl` substitution preserves each file's existing indentation (some use 8 spaces, some 12):

```bash
perl -pi -e 's/account_type TEXT NULL,/account_type TEXT NULL CHECK (account_type IN (\x5c\x27partna\x5c\x27,\x5c\x27business\x5c\x27)),/' \
  tests/Pest.php \
  tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php \
  tests/Feature/User/DataExport/DataExportTestCase.php \
  tests/Feature/Subdomain/SubdomainChangeTest.php \
  tests/Feature/Api/BootstrapHandleAliasUniquenessTest.php \
  tests/Feature/Staff/StaffAdminNotesTest.php \
  tests/Feature/FeatureFlags/FeatureFlagTestCase.php \
  tests/Feature/Moderation/QuarantineMediaJobTest.php

git diff --stat   # expect exactly 8 files, 8 insertions, 8 deletions
```

- [ ] **Step 2: Run the full suite and capture the inventory**

```bash
composer test 2>&1 | tee /private/tmp/claude-501/-Users-joshuahunter-Herd-Side-Street-backend/a69266bd-3c21-4bdc-898a-263c12473b35/scratchpad/bad-seed-inventory.txt
```

Expected: many failures, each a SQLite `CHECK constraint failed` on insert. Every distinct test file in that output is a file Tasks 3/4 must fix.

- [ ] **Step 3: Extract the distinct failing files**

```bash
grep -oE "tests/[A-Za-z0-9_/]+\.php" \
  /private/tmp/claude-501/-Users-joshuahunter-Herd-Side-Street-backend/a69266bd-3c21-4bdc-898a-263c12473b35/scratchpad/bad-seed-inventory.txt \
  | sort -u | tee /private/tmp/claude-501/-Users-joshuahunter-Herd-Side-Street-backend/a69266bd-3c21-4bdc-898a-263c12473b35/scratchpad/bad-seed-files.txt
wc -l < /private/tmp/claude-501/-Users-joshuahunter-Herd-Side-Street-backend/a69266bd-3c21-4bdc-898a-263c12473b35/scratchpad/bad-seed-files.txt
```

Record that count. Task 6 re-applies this CHECK and the suite must then be fully green.

- [ ] **Step 4: Revert the CHECK — do NOT commit it yet**

```bash
git checkout -- \
  tests/Pest.php \
  tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php \
  tests/Feature/User/DataExport/DataExportTestCase.php \
  tests/Feature/Subdomain/SubdomainChangeTest.php \
  tests/Feature/Api/BootstrapHandleAliasUniquenessTest.php \
  tests/Feature/Staff/StaffAdminNotesTest.php \
  tests/Feature/FeatureFlags/FeatureFlagTestCase.php \
  tests/Feature/Moderation/QuarantineMediaJobTest.php

git status --porcelain   # expect no modified tests/ files
```

No commit in this task. The inventory files live in scratchpad only.

---

### Task 3: Sweep `'individual'` → `'partna'`

**Files:**
- Modify: 71 files under `tests/` matching `'account_type' => 'individual'`
- Modify: `tests/Pest.php:1004` (the `createTenant` signature) and `tests/Pest.php:1019`

**Interfaces:**
- Consumes: `bad-seed-files.txt` from Task 2.
- Produces: `createTenant(string $handle): User` — a one-parameter signature. Task 4 depends on this exact signature.

- [ ] **Step 1: Sweep the seed value**

The pattern is deliberately narrow — it matches the array key assignment only, never the bare string, so KV routing and ShopBrand usages are untouched:

```bash
grep -rl "'account_type' => 'individual'" tests/ \
  | xargs perl -pi -e "s/'account_type' => 'individual'/'account_type' => 'partna'/g"

grep -rn "'account_type' => 'individual'" tests/ | wc -l   # expect 0
```

- [ ] **Step 2: Remove the dead `$type` parameter from `createTenant`**

`$type` has **zero** references inside the function body — it is pure decoration. In `tests/Pest.php:1004`, replace:

```php
function createTenant(string $handle, string $type = 'professional'): User
```

with:

```php
function createTenant(string $handle): User
```

- [ ] **Step 3: Find any caller passing a second argument**

```bash
grep -rnE "createTenant\([^)]*," tests/
```

Expected: only the two calls inside `createBrandTenant` / `createAffiliateTenant` (`createTenant($handle, 'brand')` and `createTenant($handle, 'affiliate')`). Task 4 deletes both. If any *other* caller appears, drop its second argument now.

- [ ] **Step 4: Run the suite**

```bash
composer test
```

Expected: PASS. Tests using `createBrandTenant` / `createAffiliateTenant` still pass — they overwrite `account_type` after creation and never read it back (see spec §2.1).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint tests/
git add tests/
git diff --cached --stat   # expect ~71 files
git commit -m "test: seed 'partna' instead of the retired 'individual' account type

'individual' has been forbidden by users_account_type_check since
20260612120000. The shared createTenant factory seeded it anyway, so the
suite exercised an account_type production cannot represent.

Also drops createTenant's \$type parameter — it had zero references in the
function body."
```

---

### Task 4: Retire `createBrandTenant` and `createAffiliateTenant`

**Why this is safe:** Both seed values (`'brand'`, `'partner'`) have **no `AccountType` case at all**, so `AccountType::from()` throws `ValueError` on access. Therefore no currently passing test can be reading `account_type` on these users — collapsing them to a normal tenant cannot change any assertion. The enum's own strictness certifies the refactor.

**Files:**
- Modify: `tests/Pest.php:1038-1063` (delete both helper functions)
- Modify: ~114 call sites across `tests/`

**Interfaces:**
- Consumes: `createTenant(string $handle): User` from Task 3.
- Produces: nothing.

- [ ] **Step 1: Confirm every call site passes an explicit handle**

Both helpers have default arguments (`'brand-a'`, `'affiliate-a'`) but `createTenant` requires a handle, so an argument-less call would become a fatal error:

```bash
grep -rnE "create(Brand|Affiliate)Tenant\(\s*\)" tests/
```

Expected: no matches. If any appear, rewrite those to `createTenant('brand-a')` / `createTenant('affiliate-a')` by hand in Step 2.

- [ ] **Step 2: Rewrite the call sites**

```bash
grep -rl "createBrandTenant\|createAffiliateTenant" tests/ \
  | grep -v "^tests/Pest.php$" \
  | xargs perl -pi -e "s/createBrandTenant\(/createTenant(/g; s/createAffiliateTenant\(/createTenant(/g"
```

- [ ] **Step 3: Delete both helper functions**

Delete these two functions from `tests/Pest.php` in their entirety (they sit between `createTenant` and the next helper):

```php
function createBrandTenant(string $handle = 'brand-a'): User
{
    $pro = createTenant($handle, 'brand');
    DB::connection('pgsql')
        ->table('core.users')
        ->where('id', $pro->id)
        ->update(['account_type' => 'brand']);
    AccountCapabilities::flushCache();

    return User::query()->findOrFail($pro->id);
}

function createAffiliateTenant(string $handle = 'affiliate-a'): User
{
    // A test "affiliate" is a partner (a brand-affiliated professional), not a
    // generic professional. Set account_type='partner' so AccountCapabilities
    // returns the partner capability set in dispatcher-gate tests.
    $pro = createTenant($handle, 'affiliate');
    DB::connection('pgsql')
        ->table('core.users')
        ->where('id', $pro->id)
        ->update(['account_type' => 'partner']);
    AccountCapabilities::flushCache();

    return User::query()->findOrFail($pro->id);
}
```

- [ ] **Step 4: Verify no references remain**

```bash
grep -rn "createBrandTenant\|createAffiliateTenant" tests/ | wc -l   # expect 0
grep -rn "'brand'\|'partner'" tests/ | grep account_type | wc -l      # expect 0
```

- [ ] **Step 5: Run the suite**

```bash
composer test
```

Expected: PASS. If a test now fails, it was asserting on brand/partner-specific behaviour that no longer exists — read it and report before changing the assertion.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint tests/
git add tests/
git diff --cached --stat
git commit -m "test: retire createBrandTenant/createAffiliateTenant

Both seeded account_type values ('brand', 'partner') that have no
AccountType case and are forbidden by the production CHECK constraint.
They survived because Laravel enum casts are lazy — the ValueError only
fires on attribute access, and no test read it back.

Their capability rationale died with the May standalone strip-down; there
has been no partner capability set since. Both collapse to createTenant."
```

---

### Task 5: Delete `AccountType::Individual`

**Files:**
- Modify: `app/Enums/AccountType.php`

**Interfaces:**
- Consumes: Tasks 3 and 4 (no code may seed `'individual'` before this lands).
- Produces: a two-case `AccountType` enum.

- [ ] **Step 1: Confirm nothing references the case**

```bash
grep -rn "AccountType::Individual" app/ tests/ database/ config/ routes/ | wc -l   # expect 0
```

- [ ] **Step 2: Rewrite the enum**

Replace the entire contents of `app/Enums/AccountType.php`:

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
 * Never branch on the type directly outside AccountCapabilities.
 *
 * Internal Partna staff are NOT an account type — staff identity + powers live
 * solely in core.partna_staff (role support/admin), gated by the `staff`
 * middleware and the staff Policies. A staff member may separately hold a
 * normal Partna/Business account; the two facts are independent.
 *
 * These two values are the whole domain: core.users.account_type is constrained
 * by users_account_type_check, and the test schema mirrors that CHECK so an
 * invalid seed fails at the insert.
 */
enum AccountType: string
{
    case Partna = 'partna';
    case Business = 'business';
}
```

- [ ] **Step 3: Run the suite and the analyser**

```bash
composer test
composer analyse
```

Expected: both PASS.

If `composer analyse` fails with *unmatched ignored error* entries, that is the known baseline trap — `reportUnmatchedIgnoredErrors` defaults to true, so removing code can invalidate a baseline entry and fail the build even though the change is correct. Fix by deleting the now-stale entries from `phpstan-baseline.neon` by hand. **Do not regenerate the whole baseline** — that would mask unrelated regressions.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint app/Enums/AccountType.php
git add app/Enums/AccountType.php phpstan-baseline.neon
git diff --cached --stat   # 1 file, or 2 if baseline entries were pruned
git commit -m "refactor: drop the vestigial AccountType::Individual case

Kept only so Eloquent casting survived the window between the code deploy
and the 20260612120000 backfill. That window closed on 2026-06-12; dev
holds 18 partna + 5 business rows and zero 'individual'."
```

---

### Task 6: Land the CHECK guard

**Why last:** the constraint is now a guard rather than a discovery tool. Landing it here keeps every commit in the branch green and bisectable.

**Files:**
- Modify: the same 8 files from Task 2

**Interfaces:**
- Consumes: Tasks 3, 4, 5 complete.
- Produces: permanent enforcement of valid `account_type` values in the test schema.

- [ ] **Step 1: Re-apply the CHECK**

```bash
perl -pi -e 's/account_type TEXT NULL,/account_type TEXT NULL CHECK (account_type IN (\x5c\x27partna\x5c\x27,\x5c\x27business\x5c\x27)),/' \
  tests/Pest.php \
  tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php \
  tests/Feature/User/DataExport/DataExportTestCase.php \
  tests/Feature/Subdomain/SubdomainChangeTest.php \
  tests/Feature/Api/BootstrapHandleAliasUniquenessTest.php \
  tests/Feature/Staff/StaffAdminNotesTest.php \
  tests/Feature/FeatureFlags/FeatureFlagTestCase.php \
  tests/Feature/Moderation/QuarantineMediaJobTest.php

git diff --stat   # expect exactly 8 files
```

- [ ] **Step 2: Add the explaining comment in `tests/Pest.php`**

Directly above the `account_type` line in `setupUsersTable()` (around `tests/Pest.php:311`), add:

```php
        -- Mirrors users_account_type_check in production. SQLite enforces CHECK,
        -- so a test seeding a retired value ('individual', 'brand', 'partner')
        -- fails at the INSERT rather than passing silently — enum casts are lazy,
        -- so an invalid value otherwise only throws if something reads it back.
```

- [ ] **Step 3: Run the full suite**

```bash
composer test
```

Expected: PASS, fully green. Any `CHECK constraint failed` here means Task 3 or 4 missed a file — fix it before committing rather than relaxing the constraint.

- [ ] **Step 4: Verify the guard actually bites**

Temporarily add this test to `tests/Feature/Console/BackfillUserKvEntriesTest.php`:

```php
it('TEMP guard proof', function () {
    backfillSeedUser('bad1', 'brand');
})->throws(Illuminate\Database\QueryException::class);
```

```bash
vendor/bin/pest tests/Feature/Console/BackfillUserKvEntriesTest.php
```

Expected: PASS — proving the constraint rejects a retired value. **Then delete this temporary test** and re-run the file to confirm it is green without it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint tests/
git add tests/
git diff --cached --stat   # expect exactly 8 files
git commit -m "test: enforce prod's account_type CHECK in the test schema

Tests run on SQLite and prod on Postgres, and the schemas drift — an
account_type prod forbids inserted cleanly in tests and, because enum casts
are lazy, only threw if something read it back. 'brand' survived that way
from May to July with a green suite.

SQLite enforces CHECK, so an invalid seed now fails at the insert."
```

---

### Task 7: Apply the dev migration drift — **GATED, REQUIRES SIGN-OFF**

**Do not start this task without Josh's explicit approval.** It is a database change against the live DB serving both `api.partna.au` and `dev-api.partna.au`. It is also a separate concern from the test cleanup — keep it on its own branch.

**Context:** Dev's live CHECK is the three-value form permitting `'staff'`, but `supabase_migrations.schema_migrations` records neither `20260711000000` nor `20260712000000` as applied — only `20260612120000`. The staff constraint was applied out-of-band and never recorded. Dev therefore permits an `account_type` the code retired on 2026-07-12.

**Files:**
- Apply (no edits): `supabase/migrations/20260711000000_staff_account_type.sql`, `supabase/migrations/20260712000000_retire_staff_account_type.sql`

- [ ] **Step 1: Read `20260712000000` in full**

It is ~45 lines and does more than swap the CHECK. Confirm every statement is safe to replay before proceeding, and report anything beyond the constraint change.

- [ ] **Step 2: Confirm no rows would violate the target constraint**

```sql
SELECT account_type, count(*) FROM core.users GROUP BY account_type;
```

Expected: only `partna` and `business`. Any other value blocks the `VALIDATE CONSTRAINT` step — stop and report.

- [ ] **Step 3: Present findings and wait for sign-off**

Report the contents of `20260712000000`, the row counts, and the proposed apply method. **Wait for approval.**

- [ ] **Step 4: Apply (only after approval)**

Apply both migrations in version order against ref `glncumufgaqcmqhzwrxm` via the Supabase MCP `apply_migration`, or via `supabase db push` after `supabase link --project-ref glncumufgaqcmqhzwrxm` with `--dry-run` shown first.

- [ ] **Step 5: Verify**

```sql
SELECT pg_get_constraintdef(oid) FROM pg_constraint
WHERE conrelid = 'core.users'::regclass AND conname = 'users_account_type_check';
```

Expected: `CHECK ((account_type = ANY (ARRAY['partna'::text, 'business'::text])))` — no `'staff'`.

---

## Final verification (after Task 6)

- [ ] `composer test` — green
- [ ] `composer analyse` — clean
- [ ] `grep -rn "AccountType::Individual" app/ tests/` — 0
- [ ] `grep -rn "'account_type' => 'individual'" tests/` — 0
- [ ] `grep -rn "createBrandTenant\|createAffiliateTenant" tests/` — 0
- [ ] KV routing untouched: `grep -n "'individual'" app/Jobs/Cloudflare/SyncSubdomainToKvJob.php app/Services/Accounts/AccountCapabilities.php` still returns its original 3 lines
- [ ] Prose sweep — `grep -rn "individual" docs/ scripts/audit/ | grep -i account_type` and update any stale account-type references (leave KV/ShopBrand prose alone)
