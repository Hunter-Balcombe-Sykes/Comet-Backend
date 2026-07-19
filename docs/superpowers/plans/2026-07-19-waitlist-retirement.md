# Waitlist Retirement + Early-Access PII Coverage — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retire the dead `core.waitlist_signups` capture path end to end, and wire `core.early_access_signups` into data export, account deletion, and retention.

**Architecture:** Six sequential tasks, each leaving the suite green. Tasks 1–3 *repoint* the three privacy consumers (export, purge, prune) from the dead table to the live one — retargeting rather than deleting their tests, because those tests exercise the email-keyed lookup machinery and that coverage must survive. Task 4 deletes the now-unreferenced capture path. Task 5 adds the guard test. Task 6 drops the table last, once nothing references it.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, PostgreSQL (Supabase), SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-07-19-waitlist-retirement-design.md`

## Global Constraints

- **Baseline:** branch off `development` **after** `feat/pre-account-sites-2026-07-18` merges. Branch name: `chore/retire-waitlist-capture-2026-07-19`.
- **Never create Laravel migration files.** Schema changes go in `supabase/migrations/` as raw SQL. A composer guard (`guard:no-laravel-migrations`) rejects them.
- **Line numbers in this plan are pre-merge.** The pre-account branch rewrites 113 lines of `BootstrapController` and touches `routes/api.php`, `routes/console.php`, `config/partna.php`, `AccountDeletionService`. **Locate by content, not by line number.**
- **Run the full suite** (`composer test`), never a filtered subset — deleting a model breaks same-namespace short refs that targeted runs miss.
- **Do not run `composer test` concurrently** with any test-running review subagent.
- **`php artisan pint` on changed files only.** Do not reformat untouched files — it churns the style baseline.
- **Keep `config('partna.waitlist.enabled')`.** It is the live signup kill-switch read by `PreAccountBuildController` and `PublicSignupAvailabilityController`. Not renamed.
- **Every `ShouldQueue` job needs `$backoff` and `$timeout`** (JobHygienePolicyTest). Not applicable here — no new jobs.

### Field mapping (waitlist → early_access)

`core.early_access_signups` has **no** `name`, `phone`, `applicant_type`, `industry`, `pilot_program_opt_in`, `number_of_team_members`, `consent_source`, or `last_submitted_at` column. Use this mapping when retargeting fixtures:

| waitlist_signups | early_access_signups |
|---|---|
| `name`, `industry` | `workplace_or_industry` (text) |
| `applicant_type` | `type` — **NOT NULL, CHECK IN ('partna','business')** |
| `consent_source` | `source` — NOT NULL, CHECK IN ('marketing','manual') |
| `last_submitted_at` | `created_at` |
| `phone`, `pilot_program_opt_in`, `number_of_team_members` | *(no equivalent — drop)* |
| *(n/a)* | `status` — NOT NULL, CHECK IN ('waitlist','invited','signed_up') |
| export payload key `waitlist` | `early_access` |

---

### Task 1: Repoint data export to early_access_signups

**Files:**
- Modify: `app/Services/User/DataExport/DataExportPayloadBuilder.php` (registry entry ~`:134`, `streamWaitlistSignups()` ~`:550`)
- Modify: `tests/Feature/User/DataExport/DataExportTestCase.php:97-116` (SQLite schema)
- Modify: `tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php` (8 tests)
- Modify: `tests/Feature/User/DataExport/DataExportZipWriterStreamingTest.php:78,95`

**Interfaces:**
- Produces: `DataExportPayloadBuilder::COVERED_PII_TABLES` (const array of strings) — consumed by Task 5.
- Produces: export payload key `early_access` replacing `waitlist`.

- [ ] **Step 1: Swap the SQLite schema in the export test base**

In `tests/Feature/User/DataExport/DataExportTestCase.php`, replace the `core.waitlist_signups` block (the one prefixed `// No user_id FK — joined by email_lc only.`) with:

```php
        // No user_id FK — joined by email_lc only.
        $conn->statement('CREATE TABLE IF NOT EXISTS core.early_access_signups (
            id TEXT PRIMARY KEY,
            email TEXT,
            email_lc TEXT,
            type TEXT,
            workplace_or_industry TEXT,
            platforms TEXT,
            status TEXT,
            source TEXT,
            invited_at TEXT,
            invite_token_hash TEXT,
            invite_meta TEXT,
            invited_by TEXT,
            signed_up_at TEXT,
            consent_ip_hash TEXT,
            consent_user_agent TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
```

- [ ] **Step 2: Retarget the first export test (expect FAIL)**

In `tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php`, replace the test `exports waitlist signups matched by email_lc` with:

```php
it('exports early access signups matched by email_lc', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        [
            'id' => (string) Str::uuid(),
            'email' => 'Jane@Example.com',     // mixed-case to prove email_lc match works
            'email_lc' => 'jane@example.com',
            'type' => 'partna',
            'workplace_or_industry' => 'Jane Owner Studio',
            'platforms' => '[]',
            'status' => 'waitlist',
            'source' => 'marketing',
            'created_at' => '2026-02-01T00:00:00Z',
            'updated_at' => '2026-02-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'email' => 'other@example.com',
            'email_lc' => 'other@example.com',
            'type' => 'business',
            'workplace_or_industry' => 'Someone Else Co',
            'platforms' => '[]',
            'status' => 'waitlist',
            'source' => 'marketing',
            'created_at' => '2026-02-02T00:00:00Z',
            'updated_at' => '2026-02-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['early_access'])->toHaveCount(1);
    expect($payload['early_access'][0]['workplace_or_industry'])->toBe('Jane Owner Studio');
    expect($payload['early_access'][0]['email'])->toBe('Jane@Example.com');
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php --filter="exports early access signups"`
Expected: FAIL — `Undefined array key "early_access"`.

- [ ] **Step 4: Swap the registry entry**

In `app/Services/User/DataExport/DataExportPayloadBuilder.php::sectionDescriptors()`, replace the `waitlist` entry with:

```php
            [
                'name' => 'early_access',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamEarlyAccessSignups($lookupEmail),
                'csv_columns' => ['id', 'email', 'type', 'workplace_or_industry', 'platforms', 'status', 'source', 'invited_at', 'signed_up_at', 'created_at', 'updated_at'],
            ],
```

- [ ] **Step 5: Replace the stream method**

Replace `streamWaitlistSignups()` and its docblock with:

```php
    /**
     * Early-access signup record. No FK to core.users — joined only by email_lc —
     * so the row persists after signup. Under Article 15 it is personal data of
     * the data subject and must be exported.
     *
     * Email-recycle note: EarlyAccessService upserts by email_lc, so when an email
     * is recycled the row CONTENT is overwritten with the new user's data; only
     * created_at and id survive from the prior occupant. Bounded leak — see
     * streamEmailSubscriptions comment.
     */
    private function streamEarlyAccessSignups(?string $email): Generator
    {
        $emailLc = $this->normaliseEmail($email);
        if ($emailLc === null) {
            yield from [];

            return;
        }

        // Drops consent_ip_hash + consent_user_agent (technical fingerprint),
        // email_lc (redundant with email), and invite_token_hash (credential
        // material — never exported). Mirrors the streamEnquiries redaction pattern.
        yield from $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.early_access_signups')
                ->select([
                    'id', 'email', 'type', 'workplace_or_industry',
                    'platforms', 'status', 'source',
                    'invited_at', 'signed_up_at',
                    'created_at', 'updated_at',
                ])
                ->where('email_lc', $emailLc)
        );
    }
```

- [ ] **Step 6: Run it to confirm it passes**

Run: `./vendor/bin/pest tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php --filter="exports early access signups"`
Expected: PASS

- [ ] **Step 7: Retarget the remaining 7 waitlist-fixture tests**

These use waitlist rows as fixtures for *other* behaviours — retarget, do not delete.

Every remaining `core.waitlist_signups` insert becomes this canonical block (vary `email`/`email_lc`/`workplace_or_industry`/`created_at` per test; all four of `type`, `platforms`, `status`, `source` are NOT NULL in prod and must always be present):

```php
    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'type' => 'partna',
        'workplace_or_industry' => 'Jane Owner Studio',
        'platforms' => '[]',
        'status' => 'waitlist',
        'source' => 'marketing',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);
```

and every `$payload['waitlist']` becomes `$payload['early_access']`. Per-test specifics:

| Test (by name fragment) | Change |
|---|---|
| `waitlist lookup trims whitespace` | rename to `early access lookup trims whitespace`; swap insert + `$payload['early_access']` |
| `redacts ip and user_agent fields from waitlist and handle_change_log` | rename `waitlist`→`early access`; `$waitlistRow`→`$earlyAccessRow`; keep the `not->toHaveKey('consent_ip_hash'/'consent_user_agent'/'email_lc')` assertions and **add** `expect($earlyAccessRow)->not->toHaveKey('invite_token_hash')`; swap `['name']` assertion to `['workplace_or_industry']` |
| SEM-3 / confirmed-only-snapshot test (~`:367-405`) | swap insert + assertions; `['email']` assertion unchanged |
| ASC / email-recycle test (~`:429-448`) | swap insert + assertions |
| pre-account resolution test (~`:480-517`) | swap both inserts; `['name'] === 'Jane Final'` becomes `['workplace_or_industry'] === 'Jane Final Studio'` |

Every insert needs `type`, `status`, `source`, `platforms` set (all NOT NULL in prod).

- [ ] **Step 8: Update the ZIP streaming test section list**

In `tests/Feature/User/DataExport/DataExportZipWriterStreamingTest.php`, change `:78` `'metadata', 'profile', 'site', 'waitlist',` → `'metadata', 'profile', 'site', 'early_access',` and `:95` `$zip->locateName('waitlist.csv')` → `$zip->locateName('early_access.csv')`.

- [ ] **Step 9: Add the covered-tables const (for Task 5)**

At the top of the `DataExportPayloadBuilder` class body:

```php
    /**
     * Tables whose PII this export covers. Read by DataExportCoverageTest to
     * assert no PII-bearing model is silently missing from sectionDescriptors().
     * Adding a PII table to the export means adding it here too.
     *
     * Entries MUST be schema-qualified — they are compared against the models'
     * $table values, which carry the Postgres schema prefix.
     */
    public const COVERED_PII_TABLES = [
        'core.users',
        'core.early_access_signups',
        'site.customers',
        'site.enquiries',
        'notifications.email_subscriptions',
    ];
```

- [ ] **Step 10: Run the export suite**

Run: `./vendor/bin/pest tests/Feature/User/DataExport/`
Expected: PASS, no reference to `waitlist` remaining.

- [ ] **Step 11: Commit**

```bash
php artisan pint app/Services/User/DataExport/DataExportPayloadBuilder.php
git add app/Services/User/DataExport/DataExportPayloadBuilder.php tests/Feature/User/DataExport/
git commit -m "fix(gdpr): export early_access_signups instead of dead waitlist table"
```

---

### Task 2: Repoint account-deletion purge to early_access_signups

**Files:**
- Modify: `app/Services/User/AccountDeletionService.php` (call site ~`:609`, method ~`:717`)
- Modify: `tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php:272-283` (SQLite schema)
- Modify: `tests/Feature/Account/AccountDeletionPurgePiiTest.php` (2 tests + header comment)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `purgeEarlyAccessSignup(?string $lookupEmail): void` (private).

- [ ] **Step 1: Swap the SQLite schema**

In `tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php`, replace the `core.waitlist_signups` block with:

```php
        // core.early_access_signups — purge() deletes by email_lc.
        $conn->statement('CREATE TABLE IF NOT EXISTS core.early_access_signups (
            id TEXT PRIMARY KEY,
            email TEXT NULL,
            email_lc TEXT NULL,
            type TEXT NULL,
            workplace_or_industry TEXT NULL,
            platforms TEXT NULL,
            status TEXT NULL,
            source TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');
```

- [ ] **Step 2: Retarget the dedicated purge test (expect FAIL)**

In `tests/Feature/Account/AccountDeletionPurgePiiTest.php`, replace the test `deletes waitlist signup row matched by email_lc (P2-09)` with:

```php
it('deletes early access signup row matched by email_lc (P2-09)', function () {
    [$pro, $emailLc] = seedProForPurge();

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => $emailLc,
        'email_lc' => $emailLc,
        'type' => 'partna',
        'workplace_or_industry' => 'Jane Studio',
        'platforms' => '[]',
        'status' => 'waitlist',
        'source' => 'marketing',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    app(AccountDeletionService::class)->purge($pro);

    $row = DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', $emailLc)->first();

    expect($row)->toBeNull();
});
```

> **Note:** `seedProForPurge()` is this file's existing helper — read the current test at `:99-118` and reuse whatever setup it actually performs rather than assuming this signature.

- [ ] **Step 3: Run it to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Account/AccountDeletionPurgePiiTest.php --filter="early access signup row"`
Expected: FAIL — the row still exists after purge.

- [ ] **Step 4: Swap the purge method**

In `app/Services/User/AccountDeletionService.php`, replace `purgeWaitlistSignup()` and its docblock with:

```php
    /**
     * #P2-09: Delete core.early_access_signups row matched by email_lc.
     *
     * Early-access rows are keyed on email, not user_id — no DB cascade reaches them.
     */
    private function purgeEarlyAccessSignup(?string $lookupEmail): void
    {
        if ($lookupEmail === null || trim($lookupEmail) === '') {
            return;
        }

        try {
            DB::connection('pgsql')
                ->table('core.early_access_signups')
                ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Early access signup erasure failed during account purge', [
                'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 5: Swap the call site**

Change `$this->purgeWaitlistSignup($lookupEmail);        // #P2-09: waitlist signup row` to:

```php
        $this->purgeEarlyAccessSignup($lookupEmail);     // #P2-09: early access signup row
```

- [ ] **Step 6: Run it to confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Account/AccountDeletionPurgePiiTest.php --filter="early access signup row"`
Expected: PASS

- [ ] **Step 7: Retarget the full-sweep test**

The same file has a second reference (~`:294-295` insert, `:348-350` assertion) inside the all-surfaces purge test. Swap the insert to `core.early_access_signups` using the Step 2 column set, and change the assertion comment `// P2-09: waitlist row gone` → `// P2-09: early access row gone` and its table name. Also update the file header comment at `:12` (`#P2-09 (waitlist signups)` → `#P2-09 (early access signups)`).

- [ ] **Step 8: Run the deletion suites**

Run: `./vendor/bin/pest tests/Feature/Account/ tests/Feature/User/AccountDeletion/`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
php artisan pint app/Services/User/AccountDeletionService.php
git add app/Services/User/AccountDeletionService.php tests/Feature/Account/AccountDeletionPurgePiiTest.php tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php
git commit -m "fix(gdpr): purge early_access_signups on account deletion"
```

---

### Task 3: Repoint the retention prune command

**Files:**
- Delete: `app/Console/Commands/PruneWaitlistSignupsCommand.php`
- Create: `app/Console/Commands/PruneEarlyAccessSignupsCommand.php`
- Delete: `tests/Feature/Console/PruneWaitlistSignupsCommandTest.php`
- Create: `tests/Feature/Console/PruneEarlyAccessSignupsCommandTest.php`
- Modify: `config/partna.php` (add `early_access.retention_days`; remove `waitlist.retention_days`)
- Modify: `routes/console.php` (schedule block ~`:232-240`)

**Interfaces:**
- Produces: artisan command signature `early-access:prune-old-signups {--days=} {--dry-run}`.
- Produces: config key `partna.early_access.retention_days` (int, default 730).

- [ ] **Step 1: Add the config block**

In `config/partna.php`, immediately after the `'waitlist' => [...]` block, add:

```php
    'early_access' => [
        // PRIV-8: hard-delete non-converting applicant rows older than this window.
        // signed_up rows are excluded — those are governed by account deletion.
        'retention_days' => (int) env('PARTNA_EARLY_ACCESS_RETENTION_DAYS', 730),
    ],
```

Then delete the `'retention_days' => ...` line (and its PRIV-8 comment) from the `'waitlist'` block. Leave `'enabled'` in place.

- [ ] **Step 2: Add the env key**

In `.env.example`, beside the existing `PARTNA_WAITLIST_ENABLED=false`, add:

```
PARTNA_EARLY_ACCESS_RETENTION_DAYS=730
```

Remove `PARTNA_WAITLIST_RETENTION_DAYS` if present.

- [ ] **Step 3: Write the failing test**

Create `tests/Feature/Console/PruneEarlyAccessSignupsCommandTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupEarlyAccessTable();
});

function seedEarlyAccessRow(string $emailLc, string $createdAt, string $status = 'waitlist'): void
{
    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => $emailLc,
        'email_lc' => $emailLc,
        'type' => 'partna',
        'workplace_or_industry' => 'Test Studio',
        'platforms' => '[]',
        'status' => $status,
        'source' => 'marketing',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('deletes non-converting rows older than the retention window', function () {
    seedEarlyAccessRow('old@example.com', now()->subDays(800)->toDateTimeString());
    seedEarlyAccessRow('recent@example.com', now()->subDays(10)->toDateTimeString());

    $this->artisan('early-access:prune-old-signups')->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'old@example.com')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'recent@example.com')->exists())->toBeTrue();
});

it('never deletes signed_up rows regardless of age', function () {
    seedEarlyAccessRow('converted@example.com', now()->subDays(800)->toDateTimeString(), 'signed_up');

    $this->artisan('early-access:prune-old-signups')->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'converted@example.com')->exists())->toBeTrue();
});

it('deletes nothing on a dry run', function () {
    seedEarlyAccessRow('old@example.com', now()->subDays(800)->toDateTimeString());

    $this->artisan('early-access:prune-old-signups', ['--dry-run' => true])->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'old@example.com')->exists())->toBeTrue();
});

it('honours the --days override', function () {
    seedEarlyAccessRow('old@example.com', now()->subDays(20)->toDateTimeString());

    $this->artisan('early-access:prune-old-signups', ['--days' => 5])->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'old@example.com')->exists())->toBeFalse();
});
```

- [ ] **Step 4: Run it to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Console/PruneEarlyAccessSignupsCommandTest.php`
Expected: FAIL — `Command "early-access:prune-old-signups" is not defined.`

- [ ] **Step 5: Create the command**

Create `app/Console/Commands/PruneEarlyAccessSignupsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRIV-8: Enforce the early-access applicant PII retention window for
 * NON-converting applicants.
 *
 * Hard-deletes core.early_access_signups rows older than the retention window
 * whose status is not 'signed_up'. The CONVERTING case is handled on account
 * deletion by AccountDeletionService::purgeEarlyAccessSignup() — this command
 * covers the orthogonal case of applicants who never signed up.
 */
class PruneEarlyAccessSignupsCommand extends Command
{
    protected $signature = 'early-access:prune-old-signups
                            {--days= : Override retention window (default from config partna.early_access.retention_days)}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Hard-delete non-converting core.early_access_signups rows older than the retention window. '
        .'Removes stale applicant PII (email, consent fields) the platform no longer has a basis to keep.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.early_access.retention_days', 730));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s early access signups older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would delete' : 'Deleting',
            $days,
            $cutoff->toDateTimeString()
        ));

        // status != 'signed_up' — converted applicants are governed by account
        // deletion, not retention. created_at is the only age column on this table
        // (there is no last_submitted_at equivalent).
        $query = DB::connection('pgsql')
            ->table('core.early_access_signups')
            ->where('status', '!=', 'signed_up')
            ->where('created_at', '<', $cutoff->toDateTimeString());

        if ($dryRun) {
            $count = (clone $query)->count();
            $this->info("Would delete {$count} early access signup(s).");

            Log::info('early_access.prune_old_signups_dry_run', [
                'eligible' => $count,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            return self::SUCCESS;
        }

        // Bulk delete — no per-row side-effects; the converting-applicant path
        // (AccountDeletionService::purgeEarlyAccessSignup) uses email_lc lookup and
        // is independent. No model observer on this table, so no events fire.
        $deleted = $query->delete();

        $this->info("Deleted {$deleted} early access signup(s).");

        Log::info('early_access.prune_old_signups', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Run it to confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Console/PruneEarlyAccessSignupsCommandTest.php`
Expected: PASS (4 tests)

- [ ] **Step 7: Repoint the schedule**

In `routes/console.php`, replace the waitlist schedule block with:

```php
// PRIV-8: weekly hard-delete of early_access_signups rows from non-converting applicants
// older than the retention window (default 730d). Staggered Sunday 04:30 UTC — last of the
// weekly Sunday sweeps, after the 04:10 unsubscribed-subscriptions prune and 04:20 video GC.
Schedule::command('early-access:prune-old-signups')
    ->weeklyOn(0, '04:30')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — single bulk delete; completes in seconds.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('early-access:prune-old-signups'));
```

Also update the comment at ~`:282` that references `04:30 waitlist` → `04:30 early access`.

- [ ] **Step 8: Delete the old command and its test**

```bash
git rm app/Console/Commands/PruneWaitlistSignupsCommand.php tests/Feature/Console/PruneWaitlistSignupsCommandTest.php
```

- [ ] **Step 9: Run the console suite**

Run: `./vendor/bin/pest tests/Feature/Console/`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
php artisan pint app/Console/Commands/PruneEarlyAccessSignupsCommand.php
git add -A app/Console/Commands/ tests/Feature/Console/ config/partna.php routes/console.php .env.example
git commit -m "fix(gdpr): repoint retention prune from waitlist to early_access_signups"
```

---

### Task 4: Delete the public waitlist capture path

**Files:**
- Delete: `app/Http/Controllers/Api/PublicSite/PublicWaitlistController.php`
- Delete: `app/Http/Requests/Api/PublicSite/PublicWaitlistSignupRequest.php`
- Delete: `app/Models/Core/Waitlist/WaitlistSignup.php` (and the empty `Waitlist/` dir)
- Delete: `tests/Feature/PublicSite/PublicWaitlistControllerTest.php`
- Delete: `tests/Unit/Models/WaitlistSignupTest.php`
- Modify: `routes/api.php` (route ~`:125-126`, import ~`:22`)
- Modify: `app/Providers/AppServiceProvider.php` (`RateLimiter::for('waitlist')` ~`:533-559`)
- Modify: `config/partna.php` (`waitlist.types`, `waitlist.industries`, `individual_waitlist_enabled` ~`:896-899`)
- Modify: `.env.example` (`SIDEST_INDIVIDUAL_WAITLIST_ENABLED` ~`:226`)
- Modify: `tests/Pest.php:847-873` (`setupWaitlistTable()`)
- Modify: `tests/Feature/Security/PolicyCoverageTest.php:19,42`
- Modify: `tests/Feature/Validation/RequestValidationTest.php:6,44,67`
- Modify: `tests/Feature/Security/PublicRateLimiterCfConnectingIpTest.php:50`
- Modify: `tests/Unit/AddPublicCacheHeadersTest.php:77`

**Interfaces:**
- Consumes: nothing. All three privacy consumers were repointed in Tasks 1–3.
- Produces: nothing.

- [ ] **Step 1: Confirm nothing still references the capture path**

Run: `git grep -n "WaitlistSignup\|waitlist_signups\|PublicWaitlist" -- app/ | grep -v "waitlist.enabled"`
Expected: only the files listed above. If anything else appears, STOP and reconcile before deleting.

- [ ] **Step 2: Delete the source files**

```bash
git rm app/Http/Controllers/Api/PublicSite/PublicWaitlistController.php \
       app/Http/Requests/Api/PublicSite/PublicWaitlistSignupRequest.php \
       app/Models/Core/Waitlist/WaitlistSignup.php \
       tests/Feature/PublicSite/PublicWaitlistControllerTest.php \
       tests/Unit/Models/WaitlistSignupTest.php
```

- [ ] **Step 3: Remove the route and import**

In `routes/api.php`, delete:

```php
Route::post('/public/waitlist', [PublicWaitlistController::class, 'store'])
    ->middleware(['throttle:waitlist', 'bot.token:waitlist']);
```

and the `use App\Http\Controllers\Api\PublicSite\PublicWaitlistController;` import.

- [ ] **Step 4: Remove the rate limiter**

In `app/Providers/AppServiceProvider.php`, delete the entire `RateLimiter::for('waitlist', ...)` closure and its preceding comment. Verified sole consumer was the route deleted in Step 3; `bot.token` is a bare alias with no backing config, so the `waitlist` scope string dies with the route.

- [ ] **Step 5: Remove the config keys**

In `config/partna.php`: delete `'types' => [...]` and `'industries' => [...]` from the `'waitlist'` block (leaving only `'enabled'`), and delete `'individual_waitlist_enabled' => ...` plus its preceding comment. Add a clarifying comment on what remains:

```php
    'waitlist' => [
        // Signup kill-switch, NOT a waitlist capture path (retired 2026-07-19).
        // Read by PreAccountBuildController (403 WAITLIST_ONLY) and
        // PublicSignupAvailabilityController (waitlist_only flag).
        'enabled' => (bool) env('PARTNA_WAITLIST_ENABLED', env('SIDEST_WAITLIST_ENABLED', false)),
    ],
```

In `.env.example`, delete `SIDEST_INDIVIDUAL_WAITLIST_ENABLED=false`.

- [ ] **Step 6: Remove the test schema helper**

In `tests/Pest.php`, delete `setupWaitlistTable()` and its docblock (~`:847-873`).

> **Reconciliation:** its callers are `PruneWaitlistSignupsCommandTest:23` (deleted in Task 3) and `BootstrapDivertAndDisabledTest:9`. The latter tests the individual-waitlist divert, which the pre-account branch already removed. If that test still exists post-merge and still calls this helper, delete the call; if the test itself is gone, nothing to do.

- [ ] **Step 7: Update the sweep and validation tests**

- `tests/Feature/Security/PolicyCoverageTest.php`: delete the `use App\Models\Core\Waitlist\WaitlistSignup;` import (`:19`) and the `WaitlistSignup::class, // public submission, no actor` line from `POLICY_EXEMPT` (`:42`).
- `tests/Feature/Validation/RequestValidationTest.php`: delete the import (`:6`) and both tests — `rejects invalid public waitlist payload` (`:44`) and `requires conditional fields for public waitlist payload` (`:67`).
- `tests/Feature/Security/PublicRateLimiterCfConnectingIpTest.php:50`: change `->with(['public-site', 'analytics', 'leads', 'waitlist', 'public-subscribe']);` to `->with(['public-site', 'analytics', 'leads', 'public-subscribe']);`
- `tests/Unit/AddPublicCacheHeadersTest.php:77`: delete the `'/api/public/waitlist',` entry.

- [ ] **Step 8: Run the FULL suite**

Run: `composer test`
Expected: PASS. A bare `pest` exit code of 2 means a compile fatal — usually a leftover short reference to the deleted model in a sibling file in the same namespace.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore(waitlist): delete dead public waitlist capture path"
```

---

### Task 5: Add the DataExportCoverageTest guard

**Files:**
- Create: `tests/Feature/Security/DataExportCoverageTest.php`

**Interfaces:**
- Consumes: `DataExportPayloadBuilder::COVERED_PII_TABLES` (Task 1, Step 9).

- [ ] **Step 1: Write the guard test**

Create `tests/Feature/Security/DataExportCoverageTest.php`:

```php
<?php

use App\Services\User\DataExport\DataExportPayloadBuilder;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Data Export Coverage Sweep
|--------------------------------------------------------------------------
| Every model carrying direct PII (email / consent telemetry) must either
| (a) have its table listed in DataExportPayloadBuilder::COVERED_PII_TABLES,
| or (b) appear in EXPORT_EXEMPT below with a justification.
|
| sectionDescriptors() is a hand-maintained array, so a new PII table is
| silently ABSENT rather than loudly missing — exactly how
| core.early_access_signups shipped 2026-07-10 with no export or purge
| coverage while the dead core.waitlist_signups kept both. This test turns
| that silent omission into a failing build.
*/

const EXPORT_EXEMPT = [
    // (empty — every PII-bearing model is currently exported)
];

/** Column names that mark a model as carrying direct PII. */
const PII_MARKERS = ['email', 'email_lc', 'consent_ip_hash'];

it('every PII-bearing model is covered by the data export', function () {
    $modelFiles = (new Finder)
        ->files()
        ->in(app_path('Models'))
        ->name('*.php')
        ->notName('BaseModel.php')
        ->notPath('Views')
        ->getIterator();

    $missing = [];

    foreach ($modelFiles as $file) {
        $class = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $file->getRealPath());
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }

        // Defensive: a model that cannot be no-arg constructed is not something
        // this sweep can inspect. Skip rather than fail the whole guard.
        try {
            $model = new $class;
        } catch (\Throwable) {
            continue;
        }

        $columns = array_merge($model->getFillable(), $model->getHidden());

        if (array_intersect(PII_MARKERS, $columns) === []) {
            continue;
        }

        if (in_array($class, EXPORT_EXEMPT, true)) {
            continue;
        }

        if (! in_array($model->getTable(), DataExportPayloadBuilder::COVERED_PII_TABLES, true)) {
            $missing[] = $class.' (table: '.$model->getTable().')';
        }
    }

    expect($missing)->toBe([], "PII-bearing models missing from the data export:\n  - ".implode("\n  - ", $missing)."\n\nEither add the table to DataExportPayloadBuilder::COVERED_PII_TABLES (and wire a section in sectionDescriptors()) or add the model to EXPORT_EXEMPT in this test with a justification.");
});

it('every COVERED_PII_TABLES entry is actually referenced by the builder', function () {
    $source = file_get_contents(
        app_path('Services/User/DataExport/DataExportPayloadBuilder.php')
    );

    foreach (DataExportPayloadBuilder::COVERED_PII_TABLES as $table) {
        // core.users is reached via Eloquent (metadata/profile sections), not a
        // DB::table() call, so it is exempt from the source-reference check.
        if ($table === 'core.users') {
            continue;
        }

        expect($source)->toContain(
            $table,
            "COVERED_PII_TABLES lists {$table} but the builder never references it — the entry is stale."
        );
    }
});

it('every EXPORT_EXEMPT entry resolves to a real model class', function () {
    foreach (EXPORT_EXEMPT as $class) {
        expect(class_exists($class))->toBeTrue("EXPORT_EXEMPT entry {$class} does not resolve to an existing class.");
    }
});
```

- [ ] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Feature/Security/DataExportCoverageTest.php`
Expected: PASS (3 tests). The PII models are `EarlyAccessSignup`, `EmailSubscription`, `Enquiry`, `Customer` — all four covered after Task 1.

- [ ] **Step 3: Prove the guard actually catches the bug it was written for**

Temporarily remove `'core.early_access_signups'` from `COVERED_PII_TABLES`, re-run, and confirm it FAILS naming `EarlyAccessSignup`. Then restore it. A guard test that has never been seen to fail is not yet a guard.

Run: `./vendor/bin/pest tests/Feature/Security/DataExportCoverageTest.php`
Expected: FAIL naming `App\Models\Core\EarlyAccess\EarlyAccessSignup (table: core.early_access_signups)` — then PASS again after restoring.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Security/DataExportCoverageTest.php
git commit -m "test(gdpr): guard against PII tables missing from the data export"
```

---

### Task 6: Drop core.waitlist_signups

**Files:**
- Create: `supabase/migrations/20260719000000_drop_waitlist_signups.sql`

**Interfaces:**
- Consumes: Tasks 1–4 must be complete — nothing may reference the table.

- [ ] **Step 1: Confirm zero references remain**

Run: `git grep -rn "waitlist_signups" -- app/ tests/ routes/ config/`
Expected: **no output.** If anything appears, STOP — an earlier task is incomplete.

- [ ] **Step 2: Write the migration**

Create `supabase/migrations/20260719000000_drop_waitlist_signups.sql`:

```sql
-- 20260719000000_drop_waitlist_signups.sql
--
-- Retire the V2 waitlist capture path. core.early_access_signups
-- (20260711000300) superseded it with a full waitlist → invited → signed_up
-- lifecycle plus staff CRUD; core.waitlist_signups had no read path at all —
-- its only consumers were its own prune command, the account-deletion purge,
-- and the GDPR export, all repointed at early_access_signups on 2026-07-19.
--
-- The single remaining row (1 signup, 2026-05-26, all optional fields null)
-- is dropped deliberately: early_access_signups.type is NOT NULL CHECK IN
-- ('partna','business') and the source row's applicant_type was null, so no
-- faithful migration exists.
--
-- Down: no restore. The table is gone and its data is not recoverable from
-- this migration; restore from a Supabase PITR snapshot if ever needed.

DROP TABLE IF EXISTS core.waitlist_signups;
```

- [ ] **Step 3: Dry-run against dev Supabase**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
```
Expected: the plan lists exactly this one migration.

- [ ] **Step 4: Apply**

```bash
supabase db push
```

- [ ] **Step 5: Verify on real Postgres**

The SQLite mirror drifts from prod (`type TEXT NOT NULL` without the CHECK; `created_at TEXT NULL` where prod is `NOT NULL DEFAULT now()`), so a green suite does not prove the prune predicate behaves correctly. Confirm the drop landed and the prune runs clean against real PG:

```bash
cloud tinker development --code='echo json_encode(["waitlist_gone" => ! \Illuminate\Support\Facades\Schema::connection("pgsql")->hasTable("core.waitlist_signups")]);'
cloud command:run development --cmd="early-access:prune-old-signups --dry-run"
```
Expected: `{"waitlist_gone":true}`, and the dry-run reports an eligible count without error.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260719000000_drop_waitlist_signups.sql
git commit -m "feat(db): drop retired core.waitlist_signups table"
```

---

## Post-implementation

- **Prod:** the prod DB is still on the pre-standalone schema, so this `DROP TABLE` rides the eventual prod re-baseline. Do **not** push it to `edplucmvkcnokyygxqsb` separately.
- **Frontend follow-up** (separate repo, non-blocking): remove `app/(marketing)/_components/waitlist-form.tsx`, `app/api/public/waitlist/route.ts`, the `INDIVIDUAL_WAITLIST` string in `lib/auth-errors.ts`, and the stale header comment on `(marketing)/waitlist/page.tsx`. The `/waitlist` **page stays** — it is the CTA destination and already renders `EarlyAccessForm`.
- **Audit pipeline:** no new top-level namespace is introduced, so `codebase_chunks()` in `scripts/audit/audit.sh` needs no change. Grep `scripts/audit/lenses/` for `waitlist_signups` and refresh any stale reference (AuditPipelineIntegrityTest catches dead file-path refs but not stale concepts).
