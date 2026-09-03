# Setup-Settled Email Timing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the welcome email and the outreach claim invite firing before the pre-account build has actually finished filling in; hang both on a new `settled` signal discovered by a once-a-minute sweep.

**Architecture:** `BuildProgressReader` gains an `outcome()` method that distinguishes *genuinely settled* from *hit the ceiling* from *failed* — `isDone()` is re-expressed in terms of it so existing readers are untouched. A scheduled command sweeps builds created in the last 30 minutes, stamps `settled_at` or `setup_stalled_at`, and fans out to one of two emails depending on claim state. `ClaimSiteService` keeps a send for the claim-after-settle ordering, re-gated on the new stamps.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL via Supabase raw-SQL migrations (never Laravel migrations), Redis/Horizon queues.

**Spec:** `docs/superpowers/specs/2026-09-03-setup-settled-email-timing-design.md` — read it before Task 1. The plan argues from the spec; where they disagree, the spec wins and the plan is the bug.

**Worktree:** `.worktrees/welcome-email-timing`, branch `feat/welcome-email-on-setup-complete`, based on `origin/development`. It has its own real `vendor/` and `.env` (copied, not symlinked) — Feature tests run correctly here. Do **not** touch the main checkout.

## Global Constraints

- **Never create a Laravel migration file.** Schema changes go in `supabase/migrations/` as raw SQL. A Composer guard rejects Laravel migrations and `composer test` enforces it.
- **One `CONCURRENTLY` statement per migration file, max.** This plan's migration uses none, so this is informational.
- **Every migration carries a `-- ROLLBACK:` comment block** listing the inverse statements. See `supabase/migrations/20260901190000_pre_account_builds_tier_markers.sql` for the house format.
- **Tests run SQLite, production runs Postgres.** Any new column must be added to the SQLite stand-in in `tests/Pest.php` **and** to every `tests/Postgres/` stand-in that provisions the table, or the PG lane goes red on a green `composer test`. For `core.pre_account_builds` the only PG stand-in is `tests/Postgres/ClaimConcurrencyTest.php`.
- **Fix a PG stand-in finding by ADDING the column** (`ALTER TABLE … ADD COLUMN IF NOT EXISTS …` so it survives first-creator-wins), never by thinning a table or relaxing an assertion.
- **`build_state`, `claimed_at`, `failure_code`, `thin_scrape_at` are NOT fillable on `PreAccountBuild`** (SEC-4) — state columns are written with `forceFill()`. The three columns this plan adds are state columns and follow the same rule.
- **Business logic lives in `Services/`**, not controllers or commands. A command is a thin entry point.
- **Comment for WHY, not what.** Brief docblocks on public methods, one line above a non-trivial block. No paragraphs, no banners, no restatements of the code.
- **Run `php artisan pint` before every commit.** `pint --test` is the CI gate, not `pint`.
- **Commit message trailers** — every commit in this plan ends with:
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
  ```
  Write multi-line messages to a file and use `git commit -F <file>`.
- **Sweep window:** 30 minutes (3× the 10-minute ceiling). **Ceiling:** `BuildProgressReader::CEILING_MINUTES` = 10. Do not hardcode either number twice — reference the constants.

---

### Task 1: `outcome()` on BuildProgressReader

Adds the finer-grained signal. `isDone()` keeps its exact current meaning, re-expressed in terms of the new method, so `forPoll()` and `forSite()` need no changes and their tests stay green.

**Files:**
- Modify: `app/Services/PreAccount/BuildProgressReader.php` (add `outcome()`, rewrite `isDone()` body)
- Test: `tests/Feature/PreAccount/BuildProgressOutcomeTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  ```php
  // App\Services\PreAccount\BuildProgressReader
  public const OUTCOME_PENDING = 'pending';
  public const OUTCOME_SETTLED = 'settled';
  public const OUTCOME_CEILING = 'ceiling';
  public const OUTCOME_FAILED  = 'failed';

  /** @param list<PreAccountBuildEvent> $events @param array{mirrored:int,total:int,failed?:int} $media */
  public function outcome(PreAccountBuild $build, array $events, array $media): string;

  /** Unchanged signature and meaning. */
  public function isDone(PreAccountBuild $build, array $events, array $media): bool;
  ```

- [x] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/BuildProgressOutcomeTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\BuildProgressReader;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
});

// A build with nothing outstanding: ready, content landed, no started stages,
// no media to mirror. The happy path the emails hang on.
function settledBuild(): PreAccountBuild
{
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['content_filled_at' => now(), 'enriched_at' => now()])->save();

    return $build->fresh();
}

it('reports settled when everything is answered', function () {
    $reader = app(BuildProgressReader::class);
    $build = settledBuild();

    expect($reader->outcome($build, [], ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_SETTLED);
});

it('reports pending while content has not landed', function () {
    $reader = app(BuildProgressReader::class);
    [$user, $site, $build] = makeReadyBuild(); // content_filled_at still null

    expect($reader->outcome($build->fresh(), [], ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_PENDING);
});

// The discriminating case: a build old enough that isDone() short-circuits on
// the ceiling must NOT read as settled — that is the whole point of the split.
it('reports ceiling for an unfinished build past CEILING_MINUTES', function () {
    $reader = app(BuildProgressReader::class);
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1)])->save();

    expect($reader->outcome($build->fresh(), [], ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_CEILING);
});

it('reports failed for a failed build, even inside the ceiling', function () {
    $reader = app(BuildProgressReader::class);
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED])->save();

    expect($reader->outcome($build->fresh(), [], ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_FAILED);
});

// isDone() must keep meaning exactly what it meant before: any terminal outcome.
it('keeps isDone true for every terminal outcome and false only for pending', function () {
    $reader = app(BuildProgressReader::class);
    $media = ['mirrored' => 0, 'total' => 0, 'failed' => 0];

    [$u1, $s1, $pending] = makeReadyBuild('pendingone');
    expect($reader->isDone($pending->fresh(), [], $media))->toBeFalse();

    expect($reader->isDone(settledBuild(), [], $media))->toBeTrue();
});
```

If `setupPreAccountBuildEventsTable()` does not exist in `tests/Pest.php`, add it alongside `setupPreAccountBuildsTable()`, mirroring `supabase/migrations/20260902030000_pre_account_build_events.sql`:

```php
function setupPreAccountBuildEventsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.pre_account_build_events (
        id TEXT PRIMARY KEY,
        build_id TEXT NULL,
        stage TEXT NULL,
        status TEXT NULL,
        label TEXT NULL,
        payload TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildProgressOutcomeTest.php`
Expected: FAIL — `Call to undefined method App\Services\PreAccount\BuildProgressReader::outcome()`.

- [x] **Step 3: Write minimal implementation**

In `app/Services/PreAccount/BuildProgressReader.php`, add the constants next to the existing ones and insert `outcome()` immediately above `isDone()`. Then replace `isDone()`'s **body** — keep its signature and docblock — with a delegation.

```php
    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_SETTLED = 'settled';

    public const OUTCOME_CEILING = 'ceiling';

    public const OUTCOME_FAILED = 'failed';

    /**
     * WHY it finished, not just whether. `isDone()` answers "should a loader
     * stop spinning", which deliberately says yes for a failed build and for
     * one that timed out — neither of which is a thing to email about.
     *
     * @param  list<PreAccountBuildEvent>  $events
     * @param  array{mirrored: int, total: int, failed?: int}  $media
     */
    public function outcome(PreAccountBuild $build, array $events, array $media): string
    {
        if ($build->build_state === PreAccountBuild::STATE_FAILED) {
            return self::OUTCOME_FAILED;
        }

        if ($this->settled($build, $events, $media)) {
            return self::OUTCOME_SETTLED;
        }

        // Ceiling is checked AFTER settled: a build that genuinely finished at
        // minute 9 and is read at minute 11 is settled, not timed out.
        if ($build->created_at->lt(now()->subMinutes(self::CEILING_MINUTES))) {
            return self::OUTCOME_CEILING;
        }

        return self::OUTCOME_PENDING;
    }

    /**
     * @param  list<PreAccountBuildEvent>  $events
     * @param  array{mirrored: int, total: int, failed?: int}  $media
     */
    public function isDone(PreAccountBuild $build, array $events, array $media): bool
    {
        return $this->outcome($build, $events, $media) !== self::OUTCOME_PENDING;
    }
```

Now rename the **existing** `isDone()` to `private function settled(...)`, and delete from its body the two short-circuits that `outcome()` now owns:

```php
        if ($build->build_state === PreAccountBuild::STATE_FAILED) {
            return true;
        }
        if ($build->created_at->lt(now()->subMinutes(self::CEILING_MINUTES))) {
            return true;
        }
```

Everything from `if ($build->build_state !== PreAccountBuild::STATE_READY || $build->content_filled_at === null)` downwards stays exactly as it is. Move the original method's long docblock onto `settled()` — it describes the settle rule, which is now what that method is.

⚠️ **Do not reorder the ceiling check ahead of the settle check.** `outcome()` deliberately asks "did it finish?" before "did it run out of time?", which is the opposite of the old short-circuit order. Getting this wrong makes any build read at minute 11+ report `ceiling` and silently kills the email for every slow-but-successful build.

- [x] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildProgressOutcomeTest.php`
Expected: PASS, 5 tests.

Then confirm nothing regressed for the existing readers:

Run: `./vendor/bin/pest tests/Feature/PreAccount`
Expected: PASS, 364+ tests.

- [x] **Step 5: Commit**

```bash
php artisan pint app/Services/PreAccount/BuildProgressReader.php tests/Feature/PreAccount/BuildProgressOutcomeTest.php
git add app/Services/PreAccount/BuildProgressReader.php tests/Feature/PreAccount/BuildProgressOutcomeTest.php tests/Pest.php
git commit -F .git/COMMIT_BODY
```

Message body (write to `.git/COMMIT_BODY` first):

```
feat: BuildProgressReader::outcome() splits settled from ceiling

isDone() answers "should a loader stop spinning" -- true for a failed
build and for one that timed out. Neither is a thing to email about.
outcome() names the reason; isDone() is now outcome() != pending, so
both existing readers keep their exact semantics.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 2: The three stamps

Adds `settled_at`, `setup_stalled_at`, `welcomed_at` to `core.pre_account_builds` and wires them into both test lanes' stand-ins.

**Files:**
- Create: `supabase/migrations/20260903170000_pre_account_builds_settle_stamps.sql` (plan said `20260903160000`; that version was already taken on dev by `platform_connections_owner_scope` from `c8ddc01a7`, so `db push` reported "up to date" and would have silently skipped the file)
- Modify: `app/Models/Core/User/PreAccountBuild.php` (casts + property docblocks)
- Modify: `tests/Pest.php:584-592` (the defensive ALTER list in `setupPreAccountBuildsTable`)
- Modify: `tests/Postgres/ClaimConcurrencyTest.php:212-227` (the PG stand-in CREATE TABLE)
- Test: `tests/Feature/PreAccount/BuildSettleStampsTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: three nullable `timestamptz` columns, cast to `datetime` on `PreAccountBuild`. All three are **state columns** — not fillable, written with `forceFill()`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/BuildSettleStampsTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

it('casts the three settle stamps to datetimes', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill([
        'settled_at' => now(),
        'setup_stalled_at' => now(),
        'welcomed_at' => now(),
    ])->save();

    $fresh = $build->fresh();
    expect($fresh->settled_at)->toBeInstanceOf(Carbon\CarbonInterface::class)
        ->and($fresh->setup_stalled_at)->toBeInstanceOf(Carbon\CarbonInterface::class)
        ->and($fresh->welcomed_at)->toBeInstanceOf(Carbon\CarbonInterface::class);
});

// SEC-4: state columns must not be mass-assignable. A silently dropped write
// here would strand a build unwelcomed with no error.
it('refuses to mass-assign the settle stamps', function () {
    $build = new PreAccountBuild([
        'settled_at' => now(),
        'setup_stalled_at' => now(),
        'welcomed_at' => now(),
    ]);

    expect($build->settled_at)->toBeNull()
        ->and($build->setup_stalled_at)->toBeNull()
        ->and($build->welcomed_at)->toBeNull();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildSettleStampsTest.php`
Expected: FAIL — the first test errors on the missing `settled_at` column; the second may pass vacuously (a non-fillable *and* non-existent attribute is also null), which is fine, it becomes meaningful after Step 3.

- [x] **Step 3: Write minimal implementation**

Create `supabase/migrations/20260903160000_pre_account_builds_settle_stamps.sql`:

```sql
-- Setup-settled email timing (2026-09-03): both lifecycle emails fired before
-- the build had finished filling in -- the welcome at claim (which no longer
-- waits on the build), the outreach invite at build_state=ready (which
-- precedes the whole cascade). These three stamps are the settle event's
-- record. All nullable, no backfill: existing rows stay NULL and the sweep's
-- 30-minute creation window never looks at them.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS settled_at;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS setup_stalled_at;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS welcomed_at;

alter table core.pre_account_builds add column if not exists settled_at timestamptz;

alter table core.pre_account_builds add column if not exists setup_stalled_at timestamptz;

alter table core.pre_account_builds add column if not exists welcomed_at timestamptz;

-- The sweep's candidate query: recent builds with no terminal stamp yet.
create index if not exists pre_account_builds_settle_sweep_idx
    on core.pre_account_builds (created_at)
    where settled_at is null and setup_stalled_at is null;
```

In `app/Models/Core/User/PreAccountBuild.php`, add to `$casts`:

```php
        'settled_at' => 'datetime',
        'setup_stalled_at' => 'datetime',
        'welcomed_at' => 'datetime',
```

Add the property docblocks alongside the existing ones near line 32:

```php
 * @property Carbon|null $settled_at The cascade genuinely finished (BuildProgressReader::OUTCOME_SETTLED). Stamped once by builds:settle-sweep. Not fillable (state column, SEC-4).
 * @property Carbon|null $setup_stalled_at Terminal without settling — hit the ceiling or failed. The staff record; no email is ever sent for these. Not fillable (state column, SEC-4).
 * @property Carbon|null $welcomed_at The welcome email went out. The signup lane's idempotency guard, replacing the old "did the welcome notification row insert" signal. Not fillable (state column, SEC-4).
```

Extend the SEC-4 comment above `$fillable` to name the three new columns.

In `tests/Pest.php`, add to the defensive ALTER list in `setupPreAccountBuildsTable()`:

```php
        // Mirrors migration 20260903160000 (setup-settled email timing).
        'settled_at TEXT NULL',
        'setup_stalled_at TEXT NULL',
        'welcomed_at TEXT NULL',
```

In `tests/Postgres/ClaimConcurrencyTest.php`, add three lines to the `CREATE TABLE core.pre_account_builds` block, after `published_by_claim`:

```sql
        settled_at         timestamptz,
        setup_stalled_at   timestamptz,
        welcomed_at        timestamptz,
```

and extend the comment above the CREATE TABLE to say why (claim now reads `settled_at`/`welcomed_at`, so without them the claim UPDATE `42703`s in this lane — the same failure mode `published_by_claim` documents).

- [x] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildSettleStampsTest.php`
Expected: PASS, 2 tests.

Run: `./vendor/bin/pest tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`
Expected: PASS — this is the guard that catches PG stand-in drift, and it runs in the cheap lane. If it reports a missing column, **add** the column to the stand-in; never thin the table or relax the assertion.

- [x] **Step 5: Apply the migration to dev and verify**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Then confirm the columns exist. Expected: three rows.

```bash
psql "$DEV_DB_URL" -c "select column_name from information_schema.columns where table_schema='core' and table_name='pre_account_builds' and column_name in ('settled_at','setup_stalled_at','welcomed_at') order by column_name;"
```

Do **not** apply to production. Prod's schema has diverged and prod reconciliation is separate, deferred work.

- [x] **Step 6: Commit**

```bash
php artisan pint app/Models/Core/User/PreAccountBuild.php tests/Pest.php tests/Postgres/ClaimConcurrencyTest.php tests/Feature/PreAccount/BuildSettleStampsTest.php
git add supabase/migrations/20260903160000_pre_account_builds_settle_stamps.sql app/Models/Core/User/PreAccountBuild.php tests/Pest.php tests/Postgres/ClaimConcurrencyTest.php tests/Feature/PreAccount/BuildSettleStampsTest.php
git commit -F .git/COMMIT_BODY
```

```
feat: settled_at / setup_stalled_at / welcomed_at on pre_account_builds

The settle event's record. Nullable, no backfill -- existing rows stay
NULL and the sweep's 30-minute creation window structurally cannot see
them, which is what makes the cutover safe without a migration.

State columns, so not fillable (SEC-4). Both test lanes' stand-ins
updated: the SQLite one in tests/Pest.php and the PG one in
ClaimConcurrencyTest, which claim() reads through.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 3: Connected-platform names for the email

`WelcomeMail` gains a platform-names list. Done before the settle service so the service has something real to pass.

**Files:**
- Modify: `app/Services/Platforms/ConnectionDisplayName.php` (expose `brandLabelFor()`)
- Create: `app/Services/PreAccount/ConnectedPlatformNames.php`
- Modify: `app/Mail/Account/WelcomeMail.php`
- Modify: `resources/views/emails/account/welcome.blade.php`
- Modify: `app/Http/Controllers/Dev/MailPreviewController.php:164`
- Test: `tests/Feature/Mail/WelcomeMailTest.php` (extend)
- Test: `tests/Feature/PreAccount/ConnectedPlatformNamesTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  ```php
  // App\Services\PreAccount\ConnectedPlatformNames
  /** @return list<string> Brand labels, de-duplicated, alphabetical. Empty when nothing connected. */
  public function for(User $user): array;

  // App\Mail\Account\WelcomeMail — third constructor arg, defaulted so no caller breaks
  public function __construct(
      public readonly string $recipientEmail,
      public readonly string $handle,
      public readonly array $connectedPlatforms = [],
  );

  // App\Services\Platforms\ConnectionDisplayName
  public static function brandLabelFor(string $surfaceKey): ?string;
  ```

- [x] **Step 1: Write the failing tests**

Create `tests/Feature/PreAccount/ConnectedPlatformNamesTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\PreAccount\ConnectedPlatformNames;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPlatformConnectionsTable();
});

it('returns brand labels for a user with connections, alphabetical and unique', function () {
    $user = User::factory()->create();
    IntegrationConnection::factory()->create(['user_id' => $user->id, 'surface_key' => 'instagram.profile']);
    IntegrationConnection::factory()->create(['user_id' => $user->id, 'surface_key' => 'google.business']);

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe(['Google Business', 'Instagram']);
});

// The thin-scrape case. A build that connected nothing is real and not rare
// (thin_scrape_at exists for it) and must degrade to the current email.
it('returns an empty list for a user with no connections', function () {
    $user = User::factory()->create();

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe([]);
});

// A surface the compiled catalog does not know must not produce a blank
// bullet in the email.
it('skips a surface with no resolvable brand label', function () {
    $user = User::factory()->create();
    IntegrationConnection::factory()->create(['user_id' => $user->id, 'surface_key' => 'instagram.profile']);
    IntegrationConnection::factory()->create(['user_id' => $user->id, 'surface_key' => 'nonsense.surface']);

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe(['Instagram']);
});
```

Adjust the two expected labels in the first test to whatever `bootstrap/catalog/compiled.php` actually carries for those brand keys — check with:
`php -r "\$c = require 'bootstrap/catalog/compiled.php'; print_r(array_map(fn(\$b) => \$b['label'] ?? null, \$c['brands'] ?? []));"`

Extend `tests/Feature/Mail/WelcomeMailTest.php`:

```php
it('lists connected platforms when there are any', function () {
    $html = (new WelcomeMail('sam@example.com', 'sams-cafe', ['Instagram', 'Google Business']))->render();

    expect($html)->toContain('Instagram')
        ->and($html)->toContain('Google Business');
});

// An empty list must render the pre-existing email exactly, not an empty
// bullet list or a dangling heading.
it('renders the plain welcome when nothing connected', function () {
    $html = (new WelcomeMail('sam@example.com', 'sams-cafe', []))->render();

    expect($html)->toContain('Welcome to Partna')
        ->and($html)->toContain('sams-cafe.partna.au')
        ->and($html)->not->toContain('Already connected');
});
```

- [x] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/PreAccount/ConnectedPlatformNamesTest.php tests/Feature/Mail/WelcomeMailTest.php`
Expected: FAIL — `Class "App\Services\PreAccount\ConnectedPlatformNames" not found`, and `WelcomeMail::__construct()` rejects a third argument.

- [x] **Step 3: Write minimal implementation**

In `app/Services/Platforms/ConnectionDisplayName.php`, add a public method and make the existing private one delegate to it (no logic duplicated):

```php
    /**
     * The brand's human label for a surface key — the one part of the display
     * name that is safe to show without any per-connection payload.
     * Reads bootstrap/catalog/compiled.php (a repo file, not the catalog
     * schema), so it is safe in production, which has no catalog schema.
     */
    public static function brandLabelFor(string $surfaceKey): ?string
    {
        $surface = CompiledCatalog::surface($surfaceKey);
        $label = $surface['display_name'] ?? null;
        if (! is_string($label) || $label === '') {
            $brands = CompiledCatalog::brands();
            $label = $brands[$surface['brand_key'] ?? '']['label'] ?? null;
        }

        return is_string($label) && $label !== '' ? $label : null;
    }

    private static function brandLabel(string $surfaceKey): ?string
    {
        return self::brandLabelFor($surfaceKey);
    }
```

Create `app/Services/PreAccount/ConnectedPlatformNames.php`:

```php
<?php

namespace App\Services\PreAccount;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\ConnectionDisplayName;

// The settle email's proof that the thing worked: which platforms actually
// connected. Names only, no counts (owner, 2026-09-03) -- a count is a
// promise about completeness the mirror queue cannot keep.
class ConnectedPlatformNames
{
    /** @return list<string> */
    public function for(User $user): array
    {
        $labels = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->pluck('surface_key')
            ->map(fn ($key) => ConnectionDisplayName::brandLabelFor((string) $key))
            // A surface the compiled catalog does not know yields null; a blank
            // bullet in a welcome email is worse than a shorter list.
            ->filter(fn (?string $label) => is_string($label) && $label !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_map('strval', $labels);
    }
}
```

In `app/Mail/Account/WelcomeMail.php`, add the third constructor property and pass it to the view:

```php
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $handle,
        /** @var list<string> */
        public readonly array $connectedPlatforms = [],
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Welcome to Partna — your site is live')
            ->view('emails.account.welcome', ['connectedPlatforms' => $this->connectedPlatforms]);
    }
```

Update the class docblock: the mail is no longer "queued when `ClaimSiteService::claim()` succeeds" — it is queued when the build settles, from either the sweep or claim, whichever is second.

In `resources/views/emails/account/welcome.blade.php`, insert after the "Your site is live at…" paragraph:

```blade
    @if (! empty($connectedPlatforms))
        <p class="body-text text-primary" style="margin: 0 0 8px 0; font-size: 17px; line-height: 1.47; color: #171717;">
            Already connected: {{ implode(', ', $connectedPlatforms) }}.
        </p>
    @endif
```

In `app/Http/Controllers/Dev/MailPreviewController.php:164`, give the preview a realistic list:

```php
'welcome' => ['label' => 'Welcome', 'make' => fn () => new WelcomeMail('sam@example.com', 'sams-cafe', ['Instagram', 'Google Business'])],
```

If `setupPlatformConnectionsTable()` does not exist in `tests/Pest.php`, find the helper the existing platform-connection tests use (grep `platform_connections` under `tests/`) and use that name instead.

- [x] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/ConnectedPlatformNamesTest.php tests/Feature/Mail/WelcomeMailTest.php`
Expected: PASS, 5 tests.

- [x] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/ConnectionDisplayName.php app/Services/PreAccount/ConnectedPlatformNames.php app/Mail/Account/WelcomeMail.php app/Http/Controllers/Dev/MailPreviewController.php tests/Feature/PreAccount/ConnectedPlatformNamesTest.php tests/Feature/Mail/WelcomeMailTest.php
git add -A
git commit -F .git/COMMIT_BODY
```

```
feat: welcome email lists the platforms that actually connected

Names only, no counts (owner ruling) -- a count is a promise about
completeness the mirror queue cannot keep. An empty list renders the
pre-existing email verbatim, which is the thin-scrape case and is
tested rather than hoped for.

Brand labels come from bootstrap/catalog/compiled.php, a repo file, so
this is safe in production, which carries no catalog schema.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 4: The settle service — stamping and fan-out

The one place that decides what happens when a build reaches a terminal outcome. The sweep (Task 5) and claim (Task 6) both call into it, so the two orderings cannot drift.

**Files:**
- Create: `app/Services/PreAccount/BuildSettleService.php`
- Test: `tests/Feature/PreAccount/BuildSettleServiceTest.php` (create)

**Interfaces:**
- Consumes: `BuildProgressReader::outcome()` and its constants (Task 1); `settled_at` / `setup_stalled_at` / `welcomed_at` (Task 2); `ConnectedPlatformNames::for()` (Task 3).
- Produces:
  ```php
  // App\Services\PreAccount\BuildSettleService
  /** Evaluate one build, stamp its terminal outcome, and send whatever that outcome owes. Returns the outcome. */
  public function evaluate(PreAccountBuild $build): string;

  /** Send the welcome if this build is settled + claimed + unwelcomed. Idempotent. Called by claim for the settle-then-claim ordering. */
  public function welcomeIfDue(PreAccountBuild $build): bool;
  ```

- [x] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/BuildSettleServiceTest.php`:

```php
<?php

use App\Mail\Account\WelcomeMail;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\BuildProgressReader;
use App\Services\PreAccount\BuildSettleService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    setupPlatformConnectionsTable();
    Mail::fake();
});

/** A build that outcome() will call settled, owned by a claimed account. */
function settledClaimedBuild(string $subdomain = 'janedoe'): PreAccountBuild
{
    [$user, $site, $build] = makeReadyBuild($subdomain);
    $user->forceFill(['primary_email' => 'jane@example.com', 'status' => 'active'])->save();
    $build->forceFill([
        'content_filled_at' => now(),
        'enriched_at' => now(),
        'claimed_at' => now(),
    ])->save();

    return $build->fresh();
}

it('sends the welcome and stamps both marks when settled and claimed', function () {
    $build = settledClaimedBuild();

    $outcome = app(BuildSettleService::class)->evaluate($build);

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_SETTLED)
        ->and($build->fresh()->settled_at)->not->toBeNull()
        ->and($build->fresh()->welcomed_at)->not->toBeNull();
    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->recipientEmail === 'jane@example.com');
});

it('sends exactly one welcome across repeat evaluations', function () {
    $build = settledClaimedBuild();
    $svc = app(BuildSettleService::class);

    $svc->evaluate($build);
    $svc->evaluate($build->fresh());

    Mail::assertQueued(WelcomeMail::class, 1);
});

it('stamps stalled and sends nothing at the ceiling', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill([
        'claimed_at' => now(),
        'created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1),
    ])->save();

    $outcome = app(BuildSettleService::class)->evaluate($build->fresh());

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_CEILING)
        ->and($build->fresh()->setup_stalled_at)->not->toBeNull()
        ->and($build->fresh()->settled_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('stamps stalled and sends nothing for a failed build', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'claimed_at' => now()])->save();

    $outcome = app(BuildSettleService::class)->evaluate($build->fresh());

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_FAILED)
        ->and($build->fresh()->setup_stalled_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

it('stamps nothing and sends nothing while pending', function () {
    [$user, $site, $build] = makeReadyBuild(); // content_filled_at null

    $outcome = app(BuildSettleService::class)->evaluate($build->fresh());

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_PENDING)
        ->and($build->fresh()->settled_at)->toBeNull()
        ->and($build->fresh()->setup_stalled_at)->toBeNull();
    Mail::assertNothingQueued();
});

// Settled but unclaimed: no address exists yet. The stamp lands so the sweep
// stops re-evaluating; claim sends later (Task 6).
it('stamps settled but withholds the welcome while unclaimed', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['content_filled_at' => now(), 'enriched_at' => now()])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    expect($build->fresh()->settled_at)->not->toBeNull()
        ->and($build->fresh()->welcomed_at)->toBeNull();
    Mail::assertNotQueued(WelcomeMail::class);
});

it('sends the outreach invite for a settled, published, unclaimed build', function () {
    [$user, $site, $build] = makeReadyBuild();
    $site->forceFill(['is_published' => true])->save();
    $build->forceFill([
        'content_filled_at' => now(),
        'enriched_at' => now(),
        'built_via' => PreAccountBuild::VIA_STAFF,
        'contact_email' => 'lead@example.com',
        'auto_invite' => true,
    ])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});

// The defect the spec's self-review caught: ClaimNotifier guards on
// invited_at, NOT on claim state, because it was only ever called pre-claim.
// Calling it from the settle path breaks that assumption.
it('never invites a build that is already claimed', function () {
    $build = settledClaimedBuild();
    $build->forceFill([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'contact_email' => 'lead@example.com',
        'auto_invite' => true,
    ])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    Mail::assertNotQueued(ClaimInviteMail::class);
});

it('does not invite when auto_invite is false — that build is the staff eyeball lane', function () {
    [$user, $site, $build] = makeReadyBuild();
    $site->forceFill(['is_published' => true])->save();
    $build->forceFill([
        'content_filled_at' => now(),
        'enriched_at' => now(),
        'contact_email' => 'lead@example.com',
        'auto_invite' => false,
    ])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    Mail::assertNotQueued(ClaimInviteMail::class);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildSettleServiceTest.php`
Expected: FAIL — `Class "App\Services\PreAccount\BuildSettleService" not found`.

- [x] **Step 3: Write minimal implementation**

Create `app/Services/PreAccount/BuildSettleService.php`:

```php
<?php

namespace App\Services\PreAccount;

use App\Mail\Account\WelcomeMail;
use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// What happens when a build reaches a terminal outcome. Both entry points --
// the sweep and claim -- route through here so the two orderings (claim then
// settle, settle then claim) cannot drift apart.
class BuildSettleService
{
    public function __construct(
        private readonly BuildProgressReader $progress,
        private readonly ClaimNotifier $notifier,
        private readonly ConnectedPlatformNames $platforms,
    ) {}

    /** @return string One of BuildProgressReader::OUTCOME_* */
    public function evaluate(PreAccountBuild $build): string
    {
        $outcome = $this->progress->outcome(
            $build,
            $this->progress->eventsFor($build),
            $this->progress->mediaCountsFor($build),
        );

        if ($outcome === BuildProgressReader::OUTCOME_PENDING) {
            return $outcome;
        }

        if ($outcome !== BuildProgressReader::OUTCOME_SETTLED) {
            // Ceiling or failed: terminal, but not a thing to email about
            // (owner, 2026-09-03). Stamped so the sweep stops looking and
            // staff can find it -- builds:stalled reads this column.
            if ($build->setup_stalled_at === null) {
                $build->forceFill(['setup_stalled_at' => now()])->save();
                Log::warning('build.setup_stalled', [
                    'build_id' => (string) $build->id,
                    'user_id' => (string) $build->user_id,
                    'outcome' => $outcome,
                ]);
            }

            return $outcome;
        }

        if ($build->settled_at === null) {
            $build->forceFill(['settled_at' => now()])->save();
        }

        // Two gates, mutually exclusive by claim state, so a build never
        // matches both.
        if ($build->claimed_at !== null) {
            $this->welcomeIfDue($build->fresh());
        } elseif ((bool) $build->auto_invite) {
            // The invite's own idempotency is invited_at, under ClaimNotifier's
            // advisory lock -- not re-implemented here.
            $this->notifier->notify($build->fresh());
        }

        return $outcome;
    }

    /**
     * Send the welcome if this build is settled, claimed and unwelcomed.
     *
     * Claim calls this too: the sweep is window-bounded, so a claim weeks
     * after settle would never be observed by it. Whichever of {claim, settle}
     * lands second sends; welcomed_at makes it exactly one.
     */
    public function welcomeIfDue(PreAccountBuild $build): bool
    {
        if ($build->settled_at === null || $build->claimed_at === null || $build->welcomed_at !== null) {
            return false;
        }

        $user = $build->user;
        $email = (string) ($user?->primary_email ?? '');
        $handle = (string) ($user?->site?->subdomain ?? '');
        if ($email === '' || $handle === '') {
            return false;
        }

        // Claim the send by stamping FIRST, conditionally, so a sweep tick
        // racing a claim cannot both pass the null check and both send.
        // Mirrors ClaimNotifier's invited_at discipline; delivery retries are
        // the mail queue's job, not ours.
        $claimed = DB::connection('pgsql')->table('core.pre_account_builds')
            ->where('id', $build->id)
            ->whereNull('welcomed_at')
            ->update(['welcomed_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        try {
            Mail::to($email)->queue(new WelcomeMail($email, $handle, $this->platforms->for($user)));
        } catch (\Throwable $e) {
            Log::warning('build.welcome_mail_failed', [
                'build_id' => (string) $build->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
```

`BuildProgressReader::events()` and `mediaCounts()` are currently `private`. Add two thin public wrappers to `BuildProgressReader` rather than duplicating either query:

```php
    /** @return list<PreAccountBuildEvent> */
    public function eventsFor(PreAccountBuild $build): array
    {
        return $this->events($build);
    }

    /** @return array{mirrored: int, total: int, failed: int} */
    public function mediaCountsFor(PreAccountBuild $build): array
    {
        return $this->mediaCounts($build);
    }
```

Register the service in `AppServiceProvider` only if the codebase registers comparable services explicitly; otherwise Laravel's container autowires it from the constructor types and no registration is needed. Check how `ClaimNotifier` is resolved (`app(ClaimNotifier::class)` at its call sites, no binding) and follow that.

- [x] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildSettleServiceTest.php`
Expected: PASS, 9 tests.

- [x] **Step 5: Commit**

```bash
php artisan pint app/Services/PreAccount/BuildSettleService.php app/Services/PreAccount/BuildProgressReader.php tests/Feature/PreAccount/BuildSettleServiceTest.php
git add -A
git commit -F .git/COMMIT_BODY
```

```
feat: BuildSettleService owns what a terminal outcome sends

One seam for both orderings -- claim-then-settle and settle-then-claim
-- so they cannot drift. welcomed_at is claimed by a conditional UPDATE
before the queue push, so a sweep tick racing a claim cannot double-send.

The claimed_at IS NULL gate on the outreach arm is load-bearing:
ClaimNotifier guards on invited_at, not claim state, because it was only
ever called from a pre-claim moment. Calling it from the settle path
breaks that assumption, and an owner would be told to come claim a site
they already own.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 5: The sweep command

**Files:**
- Create: `app/Console/Commands/SettleSweepCommand.php`
- Modify: `routes/console.php` (schedule it near the other pre-account entries, ~line 283)
- Test: `tests/Feature/PreAccount/SettleSweepCommandTest.php` (create)

**Interfaces:**
- Consumes: `BuildSettleService::evaluate()` (Task 4).
- Produces: `php artisan builds:settle-sweep`, scheduled `everyMinute()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/SettleSweepCommandTest.php`:

```php
<?php

use App\Mail\Account\WelcomeMail;
use App\Services\PreAccount\BuildProgressReader;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    setupPlatformConnectionsTable();
    Mail::fake();
});

it('sends the welcome for a settled claimed build inside the window', function () {
    [$user, $site, $build] = makeReadyBuild();
    $user->forceFill(['primary_email' => 'jane@example.com', 'status' => 'active'])->save();
    $build->forceFill(['content_filled_at' => now(), 'enriched_at' => now(), 'claimed_at' => now()])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertQueued(WelcomeMail::class, 1);
    expect($build->fresh()->welcomed_at)->not->toBeNull();
});

// The cutover guard. Every pre-existing build is days old, so the window is
// what makes "new builds only" true without a backfill migration.
it('never looks at a build older than the window', function () {
    [$user, $site, $build] = makeReadyBuild();
    $user->forceFill(['primary_email' => 'old@example.com', 'status' => 'active'])->save();
    $build->forceFill([
        'content_filled_at' => now(),
        'enriched_at' => now(),
        'claimed_at' => now(),
        'created_at' => now()->subDays(7),
    ])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertNothingQueued();
    expect($build->fresh()->settled_at)->toBeNull()
        ->and($build->fresh()->welcomed_at)->toBeNull();
});

it('skips a build that already carries a terminal stamp', function () {
    [$user, $site, $build] = makeReadyBuild();
    $user->forceFill(['primary_email' => 'jane@example.com', 'status' => 'active'])->save();
    $build->forceFill([
        'content_filled_at' => now(),
        'enriched_at' => now(),
        'claimed_at' => now(),
        'settled_at' => now(),
        'welcomed_at' => now(),
    ])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it('stamps a stalled build and sends nothing', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill([
        'claimed_at' => now(),
        'created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1),
    ])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    expect($build->fresh()->setup_stalled_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

// One bad build must not stop the tick -- the next build in the batch still
// gets evaluated.
it('keeps going when one build throws', function () {
    [$u1, $s1, $broken] = makeReadyBuild('brokenone');
    $broken->forceFill(['content_filled_at' => now(), 'enriched_at' => now(), 'claimed_at' => now()])->save();
    $u1->forceDelete(); // orphan the build so welcomeIfDue hits a null user

    [$u2, $s2, $good] = makeReadyBuild('goodone');
    $u2->forceFill(['primary_email' => 'good@example.com', 'status' => 'active'])->save();
    $good->forceFill(['content_filled_at' => now(), 'enriched_at' => now(), 'claimed_at' => now()])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->recipientEmail === 'good@example.com');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/SettleSweepCommandTest.php`
Expected: FAIL — `The command "builds:settle-sweep" does not exist.`

- [ ] **Step 3: Write minimal implementation**

Create `app/Console/Commands/SettleSweepCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\BuildSettleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Asks recently-created builds whether they have finished filling in.
 *
 * A timer rather than an event hook because three of the settle rule's terms
 * have no event to fire on: the media counts come from media rows, not the
 * progress ledger; stillConnecting() reads content.storefronts and
 * ingest.sources directly; and a started-but-unanswered stage stops blocking
 * after OWED_MINUTES -- a transition caused by time passing, for which there
 * is no event. Something has to look.
 *
 * The window is what keeps this cheap AND what makes the cutover safe: cost
 * scales with builds in flight rather than table size, and every build that
 * predates this feature is far older than the window, so no backfill was
 * needed.
 */
class SettleSweepCommand extends Command
{
    protected $signature = 'builds:settle-sweep {--window=30 : Only builds created within this many minutes}';

    protected $description = 'Stamp and act on pre-account builds that have reached a terminal setup outcome.';

    public function handle(BuildSettleService $settle): int
    {
        $window = max(1, (int) $this->option('window'));

        $builds = PreAccountBuild::query()
            ->where('created_at', '>=', now()->subMinutes($window))
            ->whereNull('settled_at')
            ->whereNull('setup_stalled_at')
            ->get();

        $counts = [];
        foreach ($builds as $build) {
            try {
                $outcome = $settle->evaluate($build);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            } catch (\Throwable $e) {
                // One bad build must not cost the rest of the batch its tick.
                report($e);
                $this->warn("build {$build->id}: {$e->getMessage()}");
            }
        }

        if ($counts !== []) {
            Log::info('builds.settle_sweep', ['window_minutes' => $window] + $counts);
        }
        $this->info('Swept '.$builds->count().' build(s): '.json_encode($counts));

        return self::SUCCESS;
    }
}
```

In `routes/console.php`, add next to the `builds:prune-expired` entry:

```php
// Setup-settled email timing (2026-09-03): nothing announces the moment a
// build finishes filling in, so this asks. Every minute, bounded to a
// 30-minute creation window -- cost scales with builds in flight, not table
// size. withoutOverlapping so a slow tick cannot stack.
Schedule::command('builds:settle-sweep')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('builds-settle-sweep'));
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/SettleSweepCommandTest.php`
Expected: PASS, 5 tests.

Confirm the schedule registered. Expected: one `builds:settle-sweep` row at `* * * * *`.

Run: `php artisan schedule:list | grep settle-sweep`

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Console/Commands/SettleSweepCommand.php routes/console.php tests/Feature/PreAccount/SettleSweepCommandTest.php
git add -A
git commit -F .git/COMMIT_BODY
```

```
feat: builds:settle-sweep, every minute, 30-minute window

A timer rather than an event hook because three of the settle rule's
terms have no event to fire on -- notably that a started-but-unanswered
stage stops blocking after four minutes, a transition caused by time
passing.

The window does double duty: cost scales with builds in flight rather
than table size, and every build predating this feature is far older
than 30 minutes, so "new builds only" needed no backfill migration.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 6: Re-gate the claim-side send

Claim stops sending on `is_new_claim` and starts sending on the settle stamps. Release must clear `welcomed_at` or a reclaimed site never welcomes its rightful owner.

**Files:**
- Modify: `app/Services/PreAccount/ClaimSiteService.php:275-288` (the welcome block), `:362-375` (release)
- Test: `tests/Feature/PreAccount/ClaimSiteServiceTest.php:44,89-100` (update), plus new cases

**Interfaces:**
- Consumes: `BuildSettleService::welcomeIfDue()` (Task 4).
- Produces: no new public API.

- [ ] **Step 1: Update the existing tests and add the ordering cases**

In `tests/Feature/PreAccount/ClaimSiteServiceTest.php`, the first test's assertion at line 44 currently expects a welcome on an unsettled build. Change that test to assert the opposite, and add the settled case. Replace line 44 with:

```php
    // The email no longer rides the claim: an unsettled build has nothing to
    // announce yet. The sweep sends when the cascade lands.
    Mail::assertNotQueued(WelcomeMail::class);
```

Then add, after that test:

```php
it('sends the welcome at claim when the build already settled', function () {
    Mail::fake();
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['settled_at' => now()])->save();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->recipientEmail === 'jane@example.com');
    expect($build->fresh()->welcomed_at)->not->toBeNull();
});

it('sends exactly one welcome however claim and settle are ordered', function () {
    Mail::fake();
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['settled_at' => now()])->save();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
    $this->artisan('builds:settle-sweep');

    Mail::assertQueued(WelcomeMail::class, 1);
});

// welcomed_at replaced the welcome-notification row as the email's key, so
// release has to clear it too -- otherwise the rightful owner of a released
// site is never welcomed, and nothing errors.
it('re-arms the welcome after a release', function () {
    Mail::fake();
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['settled_at' => now()])->save();
    $svc = app(ClaimSiteService::class);

    $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');
    $svc->releaseClaim($user->fresh());

    expect($build->fresh()->welcomed_at)->toBeNull();

    $svc->claim('auth-uid-2', 'newowner@example.com', 'janedoe');

    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->recipientEmail === 'newowner@example.com');
});
```

Check `releaseClaim()`'s actual signature before writing that last test — read `app/Services/PreAccount/ClaimSiteService.php` around line 340 and match it.

The double-tap test at line 89 keeps its `Mail::assertQueuedCount(1)`, but its build must now be settled for any mail to fire at all. Add `$build->forceFill(['settled_at' => now()])->save();` after its `makeReadyBuild()`, using the returned `$build`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/PreAccount/ClaimSiteServiceTest.php`
Expected: FAIL — the new settled-at-claim tests get no mail (claim still gates on `is_new_claim`), and the release test finds `welcomed_at` still set.

- [ ] **Step 3: Write minimal implementation**

In `ClaimSiteService`, replace the welcome block at lines 275-288 entirely:

```php
        // Welcome email -- sent when the build has SETTLED, not merely when the
        // account bound. Claim can happen while the build is still filling in,
        // so "your site is live" was routinely arriving at an empty page.
        //
        // This arm covers the settle-then-claim ordering only. The sweep is
        // window-bounded, so a claim weeks after settle would never be observed
        // by it -- this is load-bearing, not belt-and-braces. welcomed_at makes
        // it exactly one send across both orderings.
        $build = PreAccountBuild::query()->where('user_id', $userId)->first();
        if ($build !== null) {
            $this->afterClaim('welcome.mail', $userId, fn () => app(BuildSettleService::class)->welcomeIfDue($build));
        }
```

Add `use App\Services\PreAccount\BuildSettleService;` to the imports. `$isNewClaim` / `is_new_claim` stays exactly as it is — it still gates nothing else, but `createWelcomeNotification()` keeps creating the in-app notification at claim, which is deliberate (§7 of the spec).

In `releaseClaim()`, extend the existing notification-delete block. Immediately after the `Notification::query()->…->delete();` call, add:

```php
            // welcomed_at is the email's idempotency key since 2026-09-03 (it
            // replaced "did the welcome notification row insert"). The build row
            // survives a release, so a surviving stamp would make the next
            // claim's welcomeIfDue() a silent no-op and the rightful owner
            // would never be welcomed. Clear it for the same reason the
            // notification row above is deleted.
            $build->forceFill(['welcomed_at' => null])->save();
```

Fold it into the existing `$build->forceFill(['published_by_claim' => false])->save();` at line 397 if `$build` is in scope at both points — one write, not two. Read the method and pick whichever is cleaner; do not leave two separate `forceFill()->save()` calls on the same row in the same block.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/ClaimSiteServiceTest.php`
Expected: PASS.

Run: `./vendor/bin/pest tests/Feature/PreAccount`
Expected: PASS — no regressions across the lane.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/PreAccount/ClaimSiteService.php tests/Feature/PreAccount/ClaimSiteServiceTest.php
git add -A
git commit -F .git/COMMIT_BODY
```

```
fix: claim sends the welcome only once the build has settled

Claiming no longer waits on the build reaching ready, so "your site is
live" was routinely arriving at an empty page. Claim now sends only for
the settle-then-claim ordering; the sweep covers the other.

releaseClaim clears welcomed_at alongside the welcome notification row
it already deletes -- the stamp is the email's key now, and a survivor
would silently deny the next rightful owner their welcome.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 7: Move the outreach invite off `ready`

**Files:**
- Modify: `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php:245-255`
- Modify: `app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php:~199` (the `STATE_READY` block and its `ClaimNotifier` use)
- Test: `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php:139` (update)
- Test: `tests/Feature/PreAccount/ApproveEarlyAccessBuildJobTest.php:82,116` (update)

**Interfaces:**
- Consumes: the sweep from Task 5 now owns this send.
- Produces: no new API. `ClaimNotifier` itself is unchanged, and the manual staff endpoint still calls it directly.

- [ ] **Step 1: Update the tests to expect the new owner**

In `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php`, the assertion at line 139 expects `ClaimInviteMail` queued by the job. Change it to assert the job does **not** send, then that the sweep does:

```php
    // The invite moved off build_state=ready (2026-09-03): ready means the
    // site exists, not that the cascade finished. The sweep owns it now.
    Mail::assertNotQueued(ClaimInviteMail::class);
```

Add a companion test in the same file proving the send still happens, just later. The setup mirrors the existing invite test's — user, site, build, then the `SourceGeneratorRegistry` stub that keeps a real scrape from running:

```php
it('leaves the invite to the sweep, which sends it once settled', function () {
    Mail::fake();
    Queue::fake([SyncSubdomainToKvJob::class]);
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram', 'contact_email' => 'lead@example.com']);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    // Same generator stub as the sibling invite test — SourceGeneratorRegistry::for()
    // is typed to SiteSourceGenerator, so a duck-typed anon class trips a TypeError
    // that surfaces as a scrape failure. Copy that test's $gen block verbatim.
    $this->mock(SourceGeneratorRegistry::class, function ($mock) { /* … as in the sibling test … */ });

    (new GeneratePreAccountSiteJob($build->id, $build->source_type, publish: true))->handle(app(SourceGeneratorRegistry::class));

    // The job published and stamped ready, but sent nothing.
    Mail::assertNotQueued(ClaimInviteMail::class);

    // Settle it, then let the sweep do the send.
    $build->fresh()->forceFill(['content_filled_at' => now(), 'enriched_at' => now()])->save();
    $this->artisan('builds:settle-sweep');

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});
```

The file also has a sibling test *"does not notify when an unpublished build with contact_email reaches ready"* — it asserts no invite and must stay green untouched, since the job now never invites at all. Confirm it still passes rather than deleting it; it is now guarding a slightly different thing, which is fine.

Apply the same two changes to `ApproveEarlyAccessBuildJobTest.php` for both assertions (lines 82 and 116). Read each test's setup and copy it into the companion test rather than inventing fixtures — the early-access lane's build differs from the job lane's (it arrives already associated with an `early_access_signups` row).

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php tests/Feature/PreAccount/ApproveEarlyAccessBuildJobTest.php`
Expected: FAIL — the jobs still send, so `assertNotQueued` fails.

- [ ] **Step 3: Write minimal implementation**

In `GeneratePreAccountSiteJob`, remove the invite call from the publish block, leaving the publish and KV sync:

```php
        // Staff marketing builds go live immediately; the KV re-sync writes the
        // routing entry (with unclaimed TTL — see SyncSubdomainToKvJob) since
        // SiteObserver only auto-dispatches KV on create/subdomain-change.
        if ($this->publish) {
            $site->update(['is_published' => true]);
            SyncSubdomainToKvJob::dispatch($user->id);
            // The invite is NOT sent here (2026-09-03). ready means the site
            // exists; the Fresha auto-connect, media mirror, menu fetch and
            // workplace chain all run after it, so a lead invited now opens a
            // half-populated page. builds:settle-sweep sends it once the
            // cascade actually lands, still gated on auto_invite.
        }
```

Drop the now-unused `use App\Services\PreAccount\ClaimNotifier;` import.

Apply the equivalent change in `ApproveEarlyAccessBuildJob`: remove the `ClaimNotifier` call and its constructor/`handle()` parameter, plus the import. Read the file first — the notifier arrives as a `handle()` argument at line ~72, so removing it changes that signature.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount`
Expected: PASS across the lane.

- [ ] **Step 5: Verify the staff override survived**

The manual endpoint must still send on an unsettled build (owner ruling: staff can override).

Run: `./vendor/bin/pest tests/Feature/PreAccount/StaffInviteEndpointTest.php`
Expected: PASS, unchanged — that file should need no edits at all. If it needed edits, something touched `ClaimNotifier` or the endpoint, which this task must not do.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Jobs/PreAccount/GeneratePreAccountSiteJob.php app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php tests/Feature/PreAccount/ApproveEarlyAccessBuildJobTest.php
git add -A
git commit -F .git/COMMIT_BODY
```

```
fix: outreach invite waits for the cascade, not for build_state=ready

ready means the site exists. The Fresha auto-connect, media mirror,
menu fetch and workplace chain all run after it, so a lead invited at
ready opens a half-populated page -- their entire first impression of
Partna.

The sweep sends it once settled, still gated on auto_invite. The manual
staff endpoint is deliberately untouched: a human who has looked at the
page may send regardless (owner ruling).

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 8: The staff surface

Both halves: the column on the staff API resource, and a triage command that works with no frontend.

**Files:**
- Create: `app/Console/Commands/BuildsStalledCommand.php`
- Modify: `app/Http/Resources/StaffPreAccountBuildResource.php`
- Modify: `app/Http/Resources/UserStaffResource.php:70-82` (the `pre_account_build` block)
- Test: `tests/Feature/PreAccount/BuildsStalledCommandTest.php` (create)
- Test: `tests/Feature/PreAccount/StaffUnclaimedVisibilityTest.php` (extend)

**Interfaces:**
- Consumes: `setup_stalled_at` (Task 2).
- Produces: `php artisan builds:stalled [--hours=24]`; `settled_at` + `setup_stalled_at` on both staff payloads.

⚠️ **Both staff resources use `snake_case` keys**, unlike much of the public wire. `UserStaffResource`'s block emits `source_type`, `built_via`, `claimed_at`; `StaffPreAccountBuildResource` emits `auto_invite`, `invited_at`. Match that — a lone `setupStalledAt` beside `claimed_at` is a wire inconsistency a staff client will trip over.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PreAccount/BuildsStalledCommandTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

it('lists a stalled build', function () {
    [$user, $site, $build] = makeReadyBuild('stalledone');
    $build->forceFill(['setup_stalled_at' => now()])->save();

    $this->artisan('builds:stalled')
        ->expectsOutputToContain('stalledone')
        ->assertExitCode(0);
});

it('does not list a settled build', function () {
    [$user, $site, $build] = makeReadyBuild('finework');
    $build->forceFill(['settled_at' => now()])->save();

    $this->artisan('builds:stalled')
        ->doesntExpectOutputToContain('finework')
        ->assertExitCode(0);
});

it('honours the hours window', function () {
    [$user, $site, $build] = makeReadyBuild('ancient');
    $build->forceFill(['setup_stalled_at' => now()->subDays(5)])->save();

    $this->artisan('builds:stalled', ['--hours' => 24])
        ->doesntExpectOutputToContain('ancient')
        ->assertExitCode(0);
});
```

Extend `tests/Feature/PreAccount/StaffUnclaimedVisibilityTest.php`. That file already has a test named *"it includes the pre_account_build block for an unclaimed user with…"* — copy its setup and its staff request verbatim into a new test, then add the stamp and the two assertions:

```php
it('exposes settled_at and setup_stalled_at on the staff pre_account_build block', function () {
    // …copy the setup + staff index request from the sibling
    // "includes the pre_account_build block" test in this file…

    $build->forceFill(['setup_stalled_at' => now()])->save();

    // …re-issue that same request as $response…

    $response->assertJsonPath('data.0.pre_account_build.settled_at', null)
        ->assertJsonPath('data.0.pre_account_build.setup_stalled_at', fn ($v) => $v !== null);
});
```

Adjust `data.0` to whatever JSON path the sibling test uses — do not assume the index shape.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildsStalledCommandTest.php tests/Feature/PreAccount/StaffUnclaimedVisibilityTest.php`
Expected: FAIL — command does not exist; `setupStalledAt` missing from the payload.

- [ ] **Step 3: Write minimal implementation**

Create `app/Console/Commands/BuildsStalledCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Console\Command;

// Triage queue for builds that reached the ceiling or failed without ever
// settling -- they get no email, by ruling, so this is the only way anyone
// finds out. Same shape as catalog:unmatched: a command works the day it
// merges, with no frontend dependency.
class BuildsStalledCommand extends Command
{
    protected $signature = 'builds:stalled {--hours=24 : Only builds stalled within this many hours}';

    protected $description = 'List pre-account builds that stalled during setup and were never emailed about.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $rows = PreAccountBuild::query()
            ->with('user.site')
            ->whereNotNull('setup_stalled_at')
            ->where('setup_stalled_at', '>=', now()->subHours($hours))
            ->orderByDesc('setup_stalled_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No stalled builds in the last {$hours}h.");

            return self::SUCCESS;
        }

        $this->table(
            ['handle', 'build', 'state', 'source', 'via', 'claimed', 'stalled at'],
            $rows->map(fn (PreAccountBuild $b) => [
                (string) ($b->user?->site?->subdomain ?? '—'),
                (string) $b->id,
                (string) $b->build_state,
                $b->source_type.':'.$b->source_ref,
                (string) $b->built_via,
                $b->claimed_at !== null ? 'yes' : 'no',
                $b->setup_stalled_at?->toDateTimeString() ?? '',
            ])->all(),
        );
        $this->warn($rows->count().' stalled build(s) in the last '.$hours.'h — none received an email.');

        return self::SUCCESS;
    }
}
```

In `app/Http/Resources/StaffPreAccountBuildResource.php`, add to the returned array, next to the existing timestamps:

```php
            // Stalled during setup: reached the ceiling or failed without
            // settling. No email was sent for these, by ruling — this and
            // `builds:stalled` are the only ways staff find out.
            'settled_at' => $this->settled_at?->toIso8601String(),
            'setup_stalled_at' => $this->setup_stalled_at?->toIso8601String(),
```

In `app/Http/Resources/UserStaffResource.php`, add the same two keys to the `pre_account_build` closure, after `claimed_at`:

```php
                    'settled_at' => $this->preAccountBuild->settled_at?->toIso8601String(),
                    'setup_stalled_at' => $this->preAccountBuild->setup_stalled_at?->toIso8601String(),
```

Do **not** change that block's `$this->when(...)` presence gate — its docblock explains at length why the key must be fully absent rather than present-as-null when there is no build, and staff clients key off presence.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/BuildsStalledCommandTest.php tests/Feature/PreAccount/StaffUnclaimedVisibilityTest.php`
Expected: PASS.

- [ ] **Step 5: Update the API docs**

`docs/api.md` documents the staff payloads. Add `setupStalledAt` and `settledAt` to the `pre_account_build` block's field list there, with one line each on meaning. Find the block with `grep -n "pre_account_build" docs/api.md`.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Console/Commands/BuildsStalledCommand.php app/Http/Resources/StaffPreAccountBuildResource.php tests/Feature/PreAccount/BuildsStalledCommandTest.php tests/Feature/PreAccount/StaffUnclaimedVisibilityTest.php
git add -A
git commit -F .git/COMMIT_BODY
```

```
feat: builds:stalled + setupStalledAt on the staff payloads

A stalled build gets no email by ruling, so without a surface nobody
ever learns it happened. The column is the durable record and renders
whenever the frontend picks it up; the command works the day it merges.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01DnVGqDa1pvojPxUdbcKCKH
```

---

### Task 9: Full verification

Nothing new is written here. This is the gate before the branch is offered for merge.

- [ ] **Step 1: Pint gate**

Run: `php artisan pint --test`
Expected: PASS. Note that `pint --test` is the gate, not `pint` — a clean `pint` run says nothing.

⚠️ The Pint baseline is not clean repo-wide. If `--test` reports pre-existing violations in files this branch never touched, leave them alone and say so explicitly rather than reformatting unrelated files.

- [ ] **Step 2: Static analysis**

Run: `./vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS. PHPStan surfaces latent findings in untouched files; if a failure is not in this branch's diff, report it as pre-existing rather than fixing it here.

- [ ] **Step 3: Full suite**

Run: `composer test`
Expected: PASS. This takes roughly 20 minutes; run it once, in the background, and wait — do not poll it with `sleep`.

- [ ] **Step 4: Postgres lane**

Run: `composer test:pg`
Expected: PASS. This lane is a **required CI check** and is not part of `composer test`, so a green cheap run says nothing about it. Task 2 touched `tests/Postgres/ClaimConcurrencyTest.php`; this is where that lands.

If it reports a missing column on `core.pre_account_builds`, **add** the column to the stand-in. Never thin a table or relax an assertion to make this pass.

- [ ] **Step 5: Report honestly**

Write down, per lane: the command, the actual counts, and any failure verbatim. If a lane was not run, say which and why. Do not describe the branch as verified on the strength of a lane that was skipped.

---

## Spec coverage check

| Spec section | Task |
|---|---|
| §3 `outcome()` widening, `isDone()` re-expressed | 1 |
| §4 three stamps, no backfill, not fillable | 2 |
| §5 sweep, 30-min window, per-outcome actions | 5 |
| §6 two gates, `claimed_at IS NULL` on outreach | 4 |
| §7 claim-side send re-gated | 6 |
| §7 in-app notification stays at claim | 6 (explicitly unchanged) |
| §7 `welcomed_at` cleared on release | 6 |
| §7 invite removed from `ready` | 7 |
| §7 manual staff invite untouched | 7 (step 5 verifies) |
| §8 stalled stamp, no retry, no email | 4 |
| §8 both staff surfaces | 8 |
| §9 platform names only, empty degrades | 3 |
| §10 test list | 1, 3, 4, 5, 6, 7, 8 |
| §11 PG stand-in drift | 2, 9 |

**Known gap, deliberate:** §11's deploy-window double-send is documented as an accepted cost and has no task. If the owner asks for zero, it becomes a config timestamp bounding the sweep's lower edge — one option on `SettleSweepCommand` plus one config key, roughly a 20-line change to Task 5.
