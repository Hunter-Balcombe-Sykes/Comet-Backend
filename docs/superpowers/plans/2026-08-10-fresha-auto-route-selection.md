# Fresha Auto-Route Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a Fresha booking link is discovered from someone's Instagram, fetch the salon's menu, try to match the signing-up person to a staff member, and write either their menu or the whole store's — so the sitepage shows real services instead of a bare booking link.

**Architecture:** `LinkRouter::seedBooking` stays a DB write but dispatches the existing `ConnectFetchJob` when — and only when — the route originated from Instagram (a flag on `RouteContext`). The job runs `FreshaConnectFetch` in a new `'auto'` mode, which delegates to a new `FreshaAutoSelector` holding the match → employee-fetch → compose → project sequence. `FreshaFetch` (the scheduled refresh) delegates to the same selector as a self-heal backstop, so a failed first attempt is repaired rather than silently reverting to a healthy-looking empty row.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, Horizon/Redis queues, PostgreSQL (Supabase). Tests run SQLite in-memory.

**Spec:** `docs/superpowers/specs/2026-08-10-fresha-auto-route-selection-design.md` (v3)

## Global Constraints

- **Never create Laravel migration files.** A Composer guard rejects them. This plan needs no schema change.
- 4-space indent, LF. Comments explain WHY, not what. No banners, no restatements.
- Tests are Pest, in `tests/Feature/{domain}/` or `tests/Unit/`. Tests run **SQLite**, production is Postgres.
- `shimPgAdvisoryLockForSqlite()` is a helper each test must **call** — it is not globally registered. Any test reaching `FreshaServiceProjector::sync()` must call it or fail under SQLite.
- `QUEUE_CONNECTION=sync` in `phpunit.xml:48`. `dispatch()->afterCommit()` runs **inline** when no transaction is open, and `GeneratePreAccountSiteJob` opens none. Any test reaching `seedBooking` executes the whole Fresha fetch unless faked.
- **Acceptance criterion for the whole plan: the test suite makes no outbound Fresha calls.**
- Business logic goes in `Services/`, never controllers. No new `app/Services/` directory is created by this plan, so no audit-pipeline wiring is needed.
- `$timeout = 45` on `ConnectFetchJob` **stays** — do not raise it. Rationale in spec §Operational safeguards item 4.

## ⚠️ Before starting: sequencing hazard

Task 6 modifies `app/Services/Platforms/RouteContext.php`. A parallel work stream
(`docs/superpowers/specs/2026-08-10-link-probe-host-dedupe-design.md`) modifies the same file to add
`probesDenied`. **Confirm that work has landed, or run this plan in a separate git worktree.** Check with
`git status` and `git worktree list` before Task 6.

---

### Task 1: PersonNameParser

**Files:**
- Create: `app/Services/Profile/PersonNameParser.php`
- Test: `tests/Unit/Profile/PersonNameParserTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PersonNameParser::parse(string $fullName): array{displayName: string, firstName: string, lastName: ?string}`. Used by Task 2.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Profile\PersonNameParser;

it('splits a real person name into first and last', function () {
    expect(PersonNameParser::parse('SIMON DOYLE | Barber & Educator'))
        ->toBe(['displayName' => 'SIMON DOYLE | Barber & Educator', 'firstName' => 'SIMON', 'lastName' => 'DOYLE']);
});

it('keeps the full raw string as displayName', function () {
    expect(PersonNameParser::parse('Jane Smith – Stylist')['displayName'])->toBe('Jane Smith – Stylist');
});

it('returns a null lastName for a single token', function () {
    expect(PersonNameParser::parse('Cher'))
        ->toBe(['displayName' => 'Cher', 'firstName' => 'Cher', 'lastName' => null]);
});

it('strips the tagline after each supported separator', function (string $input) {
    $parsed = PersonNameParser::parse($input);
    expect($parsed['firstName'])->toBe('Ana')->and($parsed['lastName'])->toBe('Ruiz');
})->with([
    'Ana Ruiz | Colourist',
    'Ana Ruiz – Colourist',
    'Ana Ruiz — Colourist',
    'Ana Ruiz • Colourist',
    'Ana Ruiz|Colourist',
]);

it('takes the last token as the surname when there are middle names', function () {
    expect(PersonNameParser::parse('Mary Jane Watson')['lastName'])->toBe('Watson');
});

it('handles an empty string without error', function () {
    expect(PersonNameParser::parse(''))
        ->toBe(['displayName' => '', 'firstName' => '', 'lastName' => null]);
});

it('collapses repeated whitespace rather than emitting empty tokens', function () {
    expect(PersonNameParser::parse('Leo    Vance'))
        ->toBe(['displayName' => 'Leo    Vance', 'firstName' => 'Leo', 'lastName' => 'Vance']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/PersonNameParserTest.php`
Expected: FAIL — `Class "App\Services\Profile\PersonNameParser" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Profile;

/**
 * Splits a scraped platform "full name" into first/last parts.
 *
 * Instagram's fullName is a free-text vanity field, not a name column — it
 * routinely carries a trailing tagline ("SIMON DOYLE | Barber & Educator").
 * displayName keeps the raw string verbatim because that is what renders;
 * only the name PARTS are derived, and only for FreshaStaffMatcher's benefit.
 */
final class PersonNameParser
{
    /** Tagline separators seen in the wild, in the order they are stripped. */
    private const SEPARATORS = ['|', '–', '—', '•'];

    /** @return array{displayName: string, firstName: string, lastName: ?string} */
    public static function parse(string $fullName): array
    {
        $namePart = $fullName;
        foreach (self::SEPARATORS as $separator) {
            $namePart = explode($separator, $namePart, 2)[0];
        }

        // array_values: array_filter preserves keys, so a double space would
        // otherwise leave $tokens[0] unset.
        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($namePart)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        return [
            'displayName' => $fullName,
            'firstName' => $tokens[0] ?? '',
            'lastName' => count($tokens) > 1 ? (string) end($tokens) : null,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/PersonNameParserTest.php`
Expected: PASS (11 assertions across 7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Profile/PersonNameParser.php tests/Unit/Profile/PersonNameParserTest.php
git commit -m "feat(profile): add PersonNameParser for scraped full names"
```

---

### Task 2: Fold last_name onto the user, and move the fold above seed()

This is the **ordering fix**. Without it the matcher reads null names and the whole feature silently
degrades to storewide.

**Files:**
- Modify: `app/Services/PreAccount/Generators/InstagramSourceGenerator.php` (the name block at `:94-100`, and the `seed()` call at `:75`)
- Test: `tests/Feature/PreAccount/InstagramIdentityFoldTest.php`

**Interfaces:**
- Consumes: `PersonNameParser::parse()` from Task 1.
- Produces: `core.users.last_name` populated before `seed()` runs. Task 12's end-to-end guard depends on this.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\User\User;
use App\Services\Profile\PersonNameParser;

beforeEach(function () {
    setupUsersTable();
});

it('parses a surname out of the instagram full name', function () {
    $parsed = PersonNameParser::parse('SIMON DOYLE | Barber & Educator');

    $user = User::factory()->create([
        'display_name' => $parsed['displayName'],
        'first_name' => $parsed['firstName'],
        'last_name' => $parsed['lastName'],
    ]);

    expect($user->fresh()->last_name)->toBe('DOYLE');
});

it('writes the name onto the user before the connection seeder runs', function () {
    // Guards the ordering fix: InstagramSourceGenerator must fold the name
    // BEFORE seed(), because seed() is what routes links and dispatches the
    // Fresha auto-connect, and FreshaStaffMatcher reads first_name/last_name.
    $source = file_get_contents(base_path('app/Services/PreAccount/Generators/InstagramSourceGenerator.php'));

    $foldPosition = strpos($source, 'PersonNameParser::parse');
    $seedPosition = strpos($source, '$this->seeder->seed(');

    expect($foldPosition)->not->toBeFalse()
        ->and($seedPosition)->not->toBeFalse()
        ->and($foldPosition)->toBeLessThan($seedPosition);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/InstagramIdentityFoldTest.php`
Expected: FAIL — the second test fails because `PersonNameParser::parse` does not appear in the generator yet (`strpos` returns `false`).

- [ ] **Step 3: Write minimal implementation**

In `InstagramSourceGenerator::generate()`, **delete** the existing block that currently sits after
`$this->seeder->seed(...)`:

```php
        // Scraped identity onto the user row (spec §4): placeholder → real values.
        $fullName = trim((string) data_get($profile, 'fullName'));
        if ($fullName !== '') {
            $user->display_name = $fullName;
            $user->first_name = Str::before($fullName, ' ') ?: $fullName;
            $user->save();
        }
```

and **insert this immediately before** the `$this->seeder->seed($connection, $sourceRef, $user->id, $profile);` line:

```php
        // Scraped identity onto the user row (spec §4): placeholder → real values.
        //
        // ORDERING IS LOAD-BEARING: this must run BEFORE seed(), because seed()
        // routes the bio links (InstagramAutoSync → LinkRouter) and dispatches the
        // Fresha auto-connect, and FreshaStaffMatcher reads first_name/last_name off
        // this row. Folded after seed(), the matcher reads nulls and every account
        // silently falls through to the storewide menu — the feature would look
        // implemented and do nothing. Under QUEUE_CONNECTION=sync that is not a race,
        // it is deterministic.
        $fullName = trim((string) data_get($profile, 'fullName'));
        if ($fullName !== '') {
            $parsed = PersonNameParser::parse($fullName);
            $user->display_name = $parsed['displayName'];
            $user->first_name = $parsed['firstName'];
            $user->last_name = $parsed['lastName'];
            $user->save();
        }
```

Add `use App\Services\Profile\PersonNameParser;` to the imports. Remove the `use Illuminate\Support\Str;`
import **only if** no other usage of `Str::` remains in the file — check with
`grep -n 'Str::' app/Services/PreAccount/Generators/InstagramSourceGenerator.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PreAccount/InstagramIdentityFoldTest.php`
Expected: PASS

Then run the existing pre-account suite for regressions:
Run: `./vendor/bin/pest tests/Feature/PreAccount/`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/PreAccount/Generators/InstagramSourceGenerator.php tests/Feature/PreAccount/InstagramIdentityFoldTest.php
git commit -m "fix(pre-account): fold instagram name before routing, add last_name

The name fold ran after seeder->seed(), which is what routes bio links.
Any consumer of first_name/last_name during routing read nulls."
```

---

### Task 3: Canonicalise the Fresha URL at write time

**Files:**
- Modify: `app/Services/Platforms/FreshaScraper.php` (add after `stripLocale()` at `:47-50`)
- Modify: `app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php` (`resolveWrite()` Fresha branch, `:509-513`)
- Test: `tests/Unit/Platforms/FreshaCanonicalUrlTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `FreshaScraper::canonicalUrl(string $url): string`. Tasks 5, 10 and 11 rely on stored Fresha URLs being `/a/<slug>` shaped so `slugFromUrl()` resolves.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\FreshaScraper;

it('rewrites a book-now url to the canonical /a/ form', function () {
    expect(app(FreshaScraper::class)->canonicalUrl(
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260'
    ))->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('leaves an already canonical url untouched', function () {
    $url = 'https://www.fresha.com/a/vision-hair-studio-melbourne-520-522-city-road-tzo6gxk0';
    expect(app(FreshaScraper::class)->canonicalUrl($url))->toBe($url);
});

it('passes through a providers-shaped fresha url unchanged', function () {
    // Out of scope by design — asserted so the behaviour is deliberate.
    $url = 'https://www.fresha.com/providers/brother-wolf-bhenueul';
    expect(app(FreshaScraper::class)->canonicalUrl($url))->toBe($url);
});

it('passes through a non-fresha url unchanged', function () {
    $url = 'https://example.com/book-now/whatever/all-offer';
    expect(app(FreshaScraper::class)->canonicalUrl($url))->toBe($url);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Platforms/FreshaCanonicalUrlTest.php`
Expected: FAIL — `Call to undefined method App\Services\Platforms\FreshaScraper::canonicalUrl()`

- [ ] **Step 3: Write minimal implementation**

Add to `FreshaScraper`, directly beneath `stripLocale()`:

```php
    /**
     * Rewrite a booking-page URL to the canonical `/a/<slug>` form.
     *
     * Bio links are almost always the share URL Fresha's own app hands out
     * (`/book-now/<slug>/all-offer?share=true&pId=…`), but slugFromUrl() and the
     * connect-input validator both only understand `/a/<slug>`. Canonicalising at
     * WRITE time (resolveWrite) rather than read time is deliberate: GET
     * /platforms/fresha/team re-scrapes from payload.url, so the user's own
     * recovery path needs a usable URL just as much as our auto-fetch does.
     */
    public function canonicalUrl(string $url): string
    {
        return preg_replace(
            '#^(https?://)(?:www\.)?fresha\.com/book-now/([a-z0-9-]+)(?:/[^?\#]*)?.*$#i',
            'https://www.fresha.com/a/$2',
            $url
        ) ?? $url;
    }
```

Then in `BuildsAutoSyncFindings::resolveWrite()`, change the Fresha branch:

```php
        if ($platform === Platform::Fresha->value) {
            // Canonicalise here, not at the call site: this trait is shared by
            // three classes with unrelated constructors, and a per-class override
            // is exactly what once made LinkRouter write username:'' for every
            // Facebook link (see socialUsername below).
            return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
                'url' => app(FreshaScraper::class)->canonicalUrl($url), 'selection' => null, 'source' => 'instagram',
            ]];
        }
```

Add `use App\Services\Platforms\FreshaScraper;` to the trait's imports.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Platforms/FreshaCanonicalUrlTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/FreshaScraper.php app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php tests/Unit/Platforms/FreshaCanonicalUrlTest.php
git commit -m "feat(fresha): canonicalise book-now urls to /a/<slug> at write time"
```

---

### Task 4: FreshaStaffMatcher::matchWithTier()

**Files:**
- Modify: `app/Services/Platforms/FreshaStaffMatcher.php`
- Test: `tests/Unit/Platforms/FreshaStaffMatcherTierTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `FreshaStaffMatcher::matchWithTier(User $user, array $team): array{employeeId: ?string, tier: ?string}` where `tier` is `'exact'|'both-tokens'|'last-only'|null`. Used by Task 5. `match()` keeps its existing `?string` signature and delegates.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaStaffMatcher;

beforeEach(function () {
    setupUsersTable();
});

function team(array ...$members): array
{
    return array_map(
        static fn (array $m): array => ['employeeId' => $m[0], 'displayName' => $m[1]],
        $members
    );
}

it('reports the exact tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'], ['e2', 'Ana Ruiz'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'exact']);
});

it('reports the both-tokens tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Mr Simon Doyle Jr'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'both-tokens']);
});

it('reports the last-only tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Rob Doyle'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'last-only']);
});

it('returns nulls when the best tier is ambiguous', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'], ['e2', 'Simon Doyle'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('keeps match() behaviour identical', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    $matcher = app(FreshaStaffMatcher::class);
    $squad = team(['e1', 'Simon Doyle']);

    expect($matcher->match($user, $squad))->toBe('e1')
        ->and($matcher->match($user, []))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Platforms/FreshaStaffMatcherTierTest.php`
Expected: FAIL — `Call to undefined method ...::matchWithTier()`

- [ ] **Step 3: Write minimal implementation**

In `FreshaStaffMatcher`, add tier labels beside the existing score constants:

```php
    /** Score → reportable tier label, for the auto-selection audit trail. */
    private const TIER_LABELS = [
        self::SCORE_EXACT => 'exact',
        self::SCORE_BOTH_TOKENS => 'both-tokens',
        self::SCORE_LAST_ONLY => 'last-only',
    ];
```

Rename the existing body of `match()` to `matchWithTier()`, changing only the two `return` statements at
the end, and make `match()` delegate:

```php
    /**
     * The employeeId of the single confidently-identifiable team member, or null.
     * Unchanged behaviour — delegates to matchWithTier() so there is one algorithm.
     *
     * @param  list<array<string,mixed>>  $team
     */
    public function match(User $user, array $team): ?string
    {
        return $this->matchWithTier($user, $team)['employeeId'];
    }

    /**
     * As match(), but also reports WHICH tier fired. The auto-selection path
     * records this: the tier distribution is the only measurement that lets the
     * "no tier restriction" decision be revisited on evidence rather than intuition.
     *
     * @param  list<array<string,mixed>>  $team
     * @return array{employeeId: ?string, tier: ?string}
     */
    public function matchWithTier(User $user, array $team): array
    {
        // ... existing body verbatim, up to and including krsort($candidates); ...
    }
```

Replace the three early `return null;` statements in the moved body with
`return ['employeeId' => null, 'tier' => null];`, and replace the final return with:

```php
        krsort($candidates);
        $bestScore = (int) array_key_first($candidates);
        $best = $candidates[$bestScore];

        return count($best) === 1
            ? ['employeeId' => $best[0], 'tier' => self::TIER_LABELS[$bestScore]]
            : ['employeeId' => null, 'tier' => null];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Platforms/FreshaStaffMatcherTierTest.php`
Expected: PASS

Regression check on existing matcher coverage:
Run: `./vendor/bin/pest --filter=Fresha`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/FreshaStaffMatcher.php tests/Unit/Platforms/FreshaStaffMatcherTierTest.php
git commit -m "feat(fresha): report the matched tier via matchWithTier()"
```

---

### Task 5: FreshaAutoSelector

The core of the feature. Both the immediate path (Task 10) and the refresh backstop (Task 11) call this.

**Files:**
- Create: `app/Services/Platforms/FreshaAutoSelector.php`
- Test: `tests/Feature/Platforms/FreshaAutoSelectorTest.php`

**Interfaces:**
- Consumes: `FreshaStaffMatcher::matchWithTier()` (Task 4), `FreshaScraper::slugFromUrl()`, `FreshaScraper::fetchEmployeeServices()`, `FreshaServiceProjector::sync()`.
- Produces: `FreshaAutoSelector::select(User $user, array $menu, string $url): array{selection: array, matchTier: ?string, raw: array}`. `$menu` is `FreshaScraper::fetchMenu()` output (`{storeName, team, services}`). Tasks 10 and 11 consume this.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaAutoSelector;
use App\Services\Platforms\FreshaScraper;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    shimPgAdvisoryLockForSqlite();
});

function autoMenu(array $team = [], ?string $store = 'Anseo Studio'): array
{
    return [
        'storeName' => $store,
        'team' => $team,
        'services' => [[
            'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
            'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]],
    ];
}

$canonical = 'https://www.fresha.com/a/anseo-studio-v0v92jna';

it('selects the matched employee menu', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->once()->andReturn([[
            'serviceId' => 's:9', 'name' => 'Simon Cut', 'duration' => '45min', 'description' => null,
            'price' => 'A$80', 'priceValue' => 80, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]]);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['matchTier'])->toBe('exact')
        ->and($result['selection']['mode'])->toBe('employee')
        ->and($result['selection']['employee'])->toBeArray()
        ->and($result['selection']['employee']['employeeId'])->toBe('e1');
});

it('falls back to storewide when nothing matches', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['matchTier'])->toBeNull()
        ->and($result['selection']['mode'])->toBe('storewide')
        ->and($result['selection']['employee'])->toBeNull();
});

it('falls back to storewide when the slug cannot be extracted', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        'https://www.fresha.com/book-now/not-canonical/all-offer'
    );

    expect($result['selection']['mode'])->toBe('storewide')
        ->and($result['matchTier'])->toBe('exact'); // matched, but could not act on it
});

it('falls back to storewide when the employee menu comes back empty', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->once()->andReturn(null);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['selection']['mode'])->toBe('storewide');
});

it('projects the chosen services into site.services', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);

    app(FreshaAutoSelector::class)->select($user, autoMenu(), $canonical);

    expect(DB::table('services')->where('user_id', $user->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoSelectorTest.php`
Expected: FAIL — `Target class [App\Services\Platforms\FreshaAutoSelector] does not exist.`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Platforms;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;

/**
 * Decides WHOSE Fresha menu an auto-discovered booking link should show, and
 * projects it.
 *
 * A Fresha URL points at a salon, not a person, so a connection with no
 * `selection` is the encoding of "a human still has to choose". The dashboard
 * asks that question with a picker; an auto-routed link has no picker and no
 * human, so this unit answers it: match the account holder against the scraped
 * team, else fall back to the whole-store menu.
 *
 * Extracted rather than inlined because TWO strategies need it —
 * FreshaConnectFetch (the immediate path) and FreshaFetch (the scheduled
 * self-heal backstop) — and they have different constructor dependencies. A
 * copy in each would be the drift risk we avoided by not forking fetchStorewide.
 *
 * Every failure after the caller's single fetchMenu() degrades to a WORKING
 * storewide selection, never an error: that first scrape already returned the
 * whole-location services, so there is nothing left to fail on.
 */
final readonly class FreshaAutoSelector
{
    public function __construct(
        private FreshaScraper $scraper,
        private FreshaStaffMatcher $matcher,
        private FreshaServiceProjector $projector,
    ) {}

    /**
     * @param  array{storeName:?string, team:list<array<string,mixed>>, services:list<array<string,mixed>>}  $menu
     * @return array{selection: array<string,mixed>, matchTier: ?string, raw: list<array<string,mixed>>}
     */
    public function select(User $user, array $menu, string $url): array
    {
        $match = $this->matcher->matchWithTier($user, $menu['team']);
        $services = null;
        $employee = null;

        if ($match['employeeId'] !== null) {
            // slugFromUrl only understands /a/<slug>; a null slug means the stored
            // URL was never canonicalised, so the employee leg is impossible.
            // Guarded rather than passed through — fetchEmployeeServices(null) is
            // a TypeError, not a miss.
            $slug = $this->scraper->slugFromUrl($url);
            if ($slug !== null) {
                $services = $this->scraper->fetchEmployeeServices($slug, $match['employeeId']);
            }

            if ($services !== null && $services !== []) {
                $employee = $this->employeeFrom($menu['team'], $match['employeeId']);
            } else {
                $services = null;
            }
        }

        $mode = $services === null ? 'storewide' : 'employee';
        $services ??= $menu['services'];

        $projected = $this->projector->sync($user, $services);

        Log::info('fresha.auto_selection', [
            'user_id' => (string) $user->id,
            'mode' => $mode,
            'match_tier' => $match['tier'],
            'service_count' => count($projected['services']),
        ]);

        // services_max caps LISTING only — past it the dashboard truncates and the
        // owner cannot reach the tail to delete it. Storewide is the common outcome
        // for non-person handles, so a big salon lands here with no human in the loop.
        $cap = (int) config('partna.limits.services_max', 500);
        if (count($projected['services']) > $cap) {
            Log::warning('fresha.auto_selection.exceeds_listing_cap', [
                'user_id' => (string) $user->id,
                'projected' => count($projected['services']),
                'cap' => $cap,
            ]);
        }

        return [
            'selection' => [
                'url' => $url,
                'storeName' => $menu['storeName'],
                'mode' => $mode,
                // NESTED, not a flat id: FreshaFetch reads
                // $selection['employee']['employeeId'] and gates on mode === 'employee'.
                // A flat value makes every later refresh silently degrade to storewide.
                'employee' => $employee,
                'services' => $projected['services'],
                'hiddenServiceIds' => $projected['hiddenServiceIds'],
            ],
            'matchTier' => $match['tier'],
            'raw' => $projected['raw'],
        ];
    }

    /** @param  list<array<string,mixed>>  $team */
    private function employeeFrom(array $team, string $employeeId): ?array
    {
        foreach ($team as $member) {
            if ((string) ($member['employeeId'] ?? '') === $employeeId) {
                return ['employeeId' => $employeeId, 'displayName' => (string) ($member['displayName'] ?? '')];
            }
        }

        return null;
    }
}
```

Verify the config key for the listing cap before relying on it:
Run: `grep -n "services_max" config/partna.php`
If the key path differs from `partna.limits.services_max`, correct the `config()` call to match.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoSelectorTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/FreshaAutoSelector.php tests/Feature/Platforms/FreshaAutoSelectorTest.php
git commit -m "feat(fresha): add FreshaAutoSelector for auto-routed booking links"
```

---

### Task 6: RouteContext origin flag

> **Check the sequencing hazard at the top of this plan before starting.**

**Files:**
- Modify: `app/Services/Platforms/RouteContext.php`
- Modify: `app/Services/Platforms/InstagramAutoSync.php:78`
- Modify: `app/Jobs/Platforms/LinkInBioScanJob.php:85`
- Modify: `app/Services/Platforms/InstagramConnectionSeeder.php:262`
- Test: `tests/Unit/Platforms/RouteContextOriginTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `RouteContext::$autoConnectBooking` (public readonly bool, default `false`). Task 9 reads it.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\RouteContext;

it('defaults auto-connect to off so unmarked callers never auto-connect', function () {
    expect((new RouteContext)->autoConnectBooking)->toBeFalse();
});

it('can be constructed as an instagram-origin run', function () {
    expect((new RouteContext(autoConnectBooking: true))->autoConnectBooking)->toBeTrue();
});

it('keeps the probe budget independent of the origin flag', function () {
    $ctx = new RouteContext(maxProbes: 2, autoConnectBooking: true);
    expect($ctx->maxProbes)->toBe(2)->and($ctx->autoConnectBooking)->toBeTrue();
});

it('marks every instagram-origin construction site', function (string $file) {
    expect(file_get_contents(base_path($file)))->toContain('autoConnectBooking: true');
})->with([
    'app/Services/Platforms/InstagramAutoSync.php',
    'app/Jobs/Platforms/LinkInBioScanJob.php',
    'app/Services/Platforms/InstagramConnectionSeeder.php',
]);

it('leaves the dashboard paste path unmarked', function () {
    expect(file_get_contents(base_path('app/Http/Controllers/Api/Platforms/CustomLinksController.php')))
        ->not->toContain('autoConnectBooking');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Platforms/RouteContextOriginTest.php`
Expected: FAIL — `Unknown named parameter $autoConnectBooking`

- [ ] **Step 3: Write minimal implementation**

In `RouteContext`, extend the constructor:

```php
    /**
     * True when this run originated from a scrape of the user's OWN Instagram —
     * the only origin allowed to auto-connect a booking platform on their behalf.
     *
     * LinkRouter::route() carries no origin parameter and seedBooking genuinely
     * cannot tell its four callers apart, so the decision is made where the
     * context is built. Defaults FALSE: an unmarked call site silently does not
     * auto-connect, which is the safe direction to fail. NOT derivable from
     * payload.source — resolveWrite() hardcodes 'instagram' for every caller,
     * including a dashboard paste.
     */
    public function __construct(
        public readonly int $maxProbes = self::DEFAULT_MAX_PROBES,
        public readonly bool $autoConnectBooking = false,
    ) {}
```

Then update the three Instagram-origin construction sites:

```php
// app/Services/Platforms/InstagramAutoSync.php  (was: $ctx = new RouteContext;)
$ctx = new RouteContext(autoConnectBooking: true);

// app/Jobs/Platforms/LinkInBioScanJob.php  (was: $ctx = new RouteContext;)
// Instagram origin one hop in — the bio link was a Linktree, and its outbound
// links are still the account holder's own.
$ctx = new RouteContext(autoConnectBooking: true);

// app/Services/Platforms/InstagramConnectionSeeder.php  (autoSaveUnmatchedLinks)
// Still Instagram origin: these are bio links the main loop could not classify,
// re-routed here. A fresh context would default to false and silently skip
// auto-connect for them.
$ctx = new RouteContext(autoConnectBooking: true);
```

Leave `CustomLinksController.php:89` exactly as it is — `new RouteContext(maxProbes: 0)` already defaults
the flag to false, which is what a dashboard paste must do.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Platforms/RouteContextOriginTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/RouteContext.php app/Services/Platforms/InstagramAutoSync.php app/Jobs/Platforms/LinkInBioScanJob.php app/Services/Platforms/InstagramConnectionSeeder.php tests/Unit/Platforms/RouteContextOriginTest.php
git commit -m "feat(routing): carry instagram origin on RouteContext"
```

---

### Task 7: ConnectFetchJob systemInitiated flag

**Files:**
- Modify: `app/Jobs/Platforms/ConnectFetchJob.php` (constructor `:72-75`, notifier calls `:144` and `:220`, docblock `:35`)
- Test: `tests/Feature/Platforms/ConnectFetchSystemInitiatedTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `new ConnectFetchJob(string $connectionId, string $platform, bool $systemInitiated = false)`. Task 9 dispatches with `systemInitiated: true`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\Platforms\ConnectFetchJob;

it('defaults to human-initiated so existing call sites are unchanged', function () {
    expect((new ConnectFetchJob('c1', 'fresha'))->systemInitiated)->toBeFalse();
});

it('can be constructed as system-initiated', function () {
    expect((new ConnectFetchJob('c1', 'fresha', systemInitiated: true))->systemInitiated)->toBeTrue();
});

it('keeps the unique id independent of the flag', function () {
    expect((new ConnectFetchJob('c1', 'fresha', systemInitiated: true))->uniqueId())
        ->toBe((new ConnectFetchJob('c1', 'fresha'))->uniqueId());
});

it('guards both notifier calls behind the flag', function () {
    $source = file_get_contents(base_path('app/Jobs/Platforms/ConnectFetchJob.php'));
    // Both success paths (the 304 short-circuit and the write path) must be gated.
    expect(substr_count($source, 'if (! $this->systemInitiated)'))->toBe(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/ConnectFetchSystemInitiatedTest.php`
Expected: FAIL — `Unknown named parameter $systemInitiated`

- [ ] **Step 3: Write minimal implementation**

Extend the constructor:

```php
    public function __construct(
        public readonly string $connectionId,
        public readonly string $platform,
        // TRUE when nothing human triggered this connect — the auto-route path.
        // Suppresses the "connected!" notice: a pre-account user has no email and
        // never asked for this connection. Defaults FALSE so the three dashboard
        // call sites are byte-identical.
        public readonly bool $systemInitiated = false,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }
```

Guard **both** `$notifier->connected($connection);` calls:

```php
            if (! $this->systemInitiated) {
                $notifier->connected($connection);
            }
```

Update the class docblock — replace the "a human is watching the modal, not a cron" reasoning with:

```php
// Callers are NOT uniformly interactive. The three dashboard controllers have a
// human watching a modal; LinkRouter's auto-route dispatch has nobody, and no
// interactive retry path at all. The tries/backoff/uniqueFor tuning below is
// still sized for the interactive case (it remains correct for both), but the
// auto path is why a runtime kill switch and the FreshaFetch self-heal backstop
// exist — a failure there is never noticed by a user who could retry it.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/ConnectFetchSystemInitiatedTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Platforms/ConnectFetchJob.php tests/Feature/Platforms/ConnectFetchSystemInitiatedTest.php
git commit -m "feat(platforms): add systemInitiated flag to ConnectFetchJob"
```

---

### Task 8: Config — kill switch, daily ceiling, scrape cache

**Files:**
- Modify: `config/partna.php` (inside the existing `'connect' => [...]` block, beside `'deferred'` at `:1701`)
- Modify: `.env.example`
- Test: `tests/Unit/Config/AutoBookingConfigTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `config('partna.connect.auto_booking.enabled')` (bool), `.global_daily_cap` (int), `.menu_cache_seconds` (int). Tasks 9 and 10 read these.

- [ ] **Step 1: Write the failing test**

```php
<?php

it('enables auto booking connect by default', function () {
    expect(config('partna.connect.auto_booking.enabled'))->toBeTrue();
});

it('caps auto booking fetches globally per day', function () {
    expect(config('partna.connect.auto_booking.global_daily_cap'))->toBe(500);
});

it('caches the salon menu scrape for an hour', function () {
    expect(config('partna.connect.auto_booking.menu_cache_seconds'))->toBe(3600);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/AutoBookingConfigTest.php`
Expected: FAIL — all three assert against `null`

- [ ] **Step 3: Write minimal implementation**

Add inside the `'connect'` array in `config/partna.php`, directly after the `'deferred'` line:

```php
        // Auto-connect of a booking platform discovered by LinkRouter from a
        // user's own Instagram. DELIBERATELY NOT part of 'deferred' above:
        // that key means "this platform uses the deferred connect flow", and
        // overloading it to also mean "auto-connect is on" would conflate two
        // independent things — flipping it to stop runaway auto-fetches would
        // also break every dashboard connect.
        'auto_booking' => [
            'enabled' => (bool) env('PARTNA_AUTO_BOOKING_ENABLED', true),

            // Ceiling on outbound salon-page scrapes per day across the whole
            // install. Mirrors partna.routing.probe's global_daily_cap: an
            // unbounded outbound request made on a user's say-so is an
            // amplification vector aimed at someone else. Generous, because
            // builds are serialised at one concurrent (supervisor-long,
            // maxProcesses => 1) — but a real ceiling if that ever changes.
            'global_daily_cap' => (int) env('PARTNA_AUTO_BOOKING_DAILY_CAP', 500),

            // Two people at one salon signing up would otherwise scrape the same
            // page twice. Deliberately shorter than team_cache_seconds (86400):
            // this menu feeds DISPLAYED PRICING, not just a picker roster.
            'menu_cache_seconds' => (int) env('PARTNA_AUTO_BOOKING_MENU_CACHE_SECONDS', 3600),
        ],
```

Add to `.env.example`, near the other `PARTNA_CONNECT_*` entries:

```
PARTNA_AUTO_BOOKING_ENABLED=true
PARTNA_AUTO_BOOKING_DAILY_CAP=500
PARTNA_AUTO_BOOKING_MENU_CACHE_SECONDS=3600
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan config:clear && ./vendor/bin/pest tests/Unit/Config/AutoBookingConfigTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/partna.php .env.example tests/Unit/Config/AutoBookingConfigTest.php
git commit -m "feat(config): add auto-booking kill switch, daily cap and menu cache ttl"
```

---

### Task 9: Dispatch from seedBooking

**Files:**
- Modify: `app/Services/Platforms/LinkRouter.php` (`seedBooking()`, around `:180-208`)
- Test: `tests/Feature/Platforms/FreshaAutoDispatchTest.php`

**Interfaces:**
- Consumes: `RouteContext::$autoConnectBooking` (Task 6), `ConnectFetchJob` systemInitiated (Task 7), config keys (Task 8), canonical URLs (Task 3).
- Produces: a queued `ConnectFetchJob` and `payload.connectMode = 'auto'` on the row. Tasks 10 and 11 read `connectMode`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
    Queue::fake();
});

$freshaUrl = 'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260';

it('dispatches an auto connect for an instagram-origin fresha link', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertPushed(ConnectFetchJob::class, fn (ConnectFetchJob $job): bool => $job->platform === 'fresha' && $job->systemInitiated === true);
});

it('stamps connectMode auto and a canonical url on the row', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;

    expect($payload['connectMode'])->toBe('auto')
        ->and($payload['url'])->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('does NOT dispatch for a dashboard paste', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    // The shape CustomLinksController uses: origin flag left at its default.
    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(maxProbes: 0));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT dispatch when the kill switch is off', function () use ($freshaUrl) {
    config()->set('partna.connect.auto_booking.enabled', false);
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT dispatch when the booking gate denies the link', function () use ($freshaUrl) {
    // business + food sector: gateAllows() returns false for 'booking'.
    $user = User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT dispatch once the global daily cap is spent', function () use ($freshaUrl) {
    config()->set('partna.connect.auto_booking.global_daily_cap', 1);
    Cache::put('fresha:auto-connect:daily:'.now()->toDateString(), 1, now()->addDay());

    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('counts each dispatch against the daily cap', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    expect((int) Cache::get('fresha:auto-connect:daily:'.now()->toDateString()))->toBe(1);
});
```

Add `use Illuminate\Support\Facades\Cache;` to the test file's imports.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoDispatchTest.php`
Expected: FAIL — first test fails, no `ConnectFetchJob` pushed

- [ ] **Step 3: Write minimal implementation**

In `LinkRouter::seedBooking()`, after `$result = $this->withBookingXorLock(...)` and before
`return $this->outcomeFrom(...)`, insert:

```php
        $outcome = $this->outcomeFrom($result, $write, $classified);

        // Auto-connect the menu. Gated on the ORIGIN flag, not the call site:
        // route() has four callers and cannot tell them apart, and a dashboard
        // paste must never trigger this. Only on a real seed — a conflict, gate
        // denial or lock contention wrote nothing to fetch for.
        if ($outcome->outcome === 'seeded'
            && $platform === Platform::Fresha->value
            && $ctx->autoConnectBooking
            && (bool) config('partna.connect.auto_booking.enabled', true)
        ) {
            $this->dispatchAutoBookingConnect($user);
        }

        return $outcome;
```

Change the method signature to accept the context: `private function seedBooking(User $user, string $platform, string $url, array $classified, RouteContext $ctx): RouteResult`, and update its single call site in `routeClassified()` to `$this->seedBooking($user, $platform, $url, $classified, $ctx)`.

Add the helper:

```php
    /**
     * Resolve the row we just wrote and hand it to ConnectFetchJob.
     *
     * Re-queried rather than threaded back through write()/resolveBookingLink()/
     * RouteResult: those live in a trait shared with GoogleBusinessAutoSync, and
     * widening their return types to carry an id is exactly the blast radius this
     * change is scoped to avoid. One indexed lookup is cheaper than that coupling.
     *
     * connectMode is stamped HERE, not in resolveWrite(), because resolveWrite is
     * shared with non-Instagram callers that must not be marked auto.
     */
    private function dispatchAutoBookingConnect(User $user): void
    {
        if (! $this->claimAutoBookingBudget()) {
            return;
        }

        $row = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Fresha->value)
            ->first();

        if ($row === null) {
            return;
        }

        $row->forceFill(['payload' => [...$row->payload, 'connectMode' => 'auto']])->saveQuietly();

        ConnectFetchJob::dispatch((string) $row->id, Platform::Fresha->value, systemInitiated: true)->afterCommit();
    }

    /**
     * Install-wide daily ceiling on auto-triggered salon scrapes.
     *
     * Mirrors partna.routing.probe's global_daily_cap and exists for the same
     * reason: an unbounded outbound request the backend makes on a user's say-so
     * is a reliability risk to us and an amplification vector aimed at someone
     * else. Today the real limiter is that builds are serialised
     * (supervisor-long, maxProcesses => 1) — this is what survives that changing.
     *
     * Fails OPEN on a cache outage: losing the ceiling is a smaller harm than
     * silently stopping every signup from connecting its booking menu.
     */
    private function claimAutoBookingBudget(): bool
    {
        $cap = (int) config('partna.connect.auto_booking.global_daily_cap', 500);
        $key = 'fresha:auto-connect:daily:'.now()->toDateString();

        try {
            if ((int) Cache::get($key, 0) >= $cap) {
                Log::warning('fresha.auto_connect.daily_cap_reached', ['cap' => $cap]);

                return false;
            }

            // add() then increment(): add() only sets the TTL on first write, so a
            // bare increment() on a missing key would create one with NO expiry —
            // and every cache key in this app must carry a TTL (volatile-lru).
            Cache::add($key, 0, now()->addDay());
            Cache::increment($key);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }
```

Add `use Illuminate\Support\Facades\Cache;` and `use Illuminate\Support\Facades\Log;` to `LinkRouter`'s
imports if not already present.

Add imports for `App\Jobs\Platforms\ConnectFetchJob` and `App\Models\Core\Site\IntegrationConnection`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoDispatchTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/LinkRouter.php tests/Feature/Platforms/FreshaAutoDispatchTest.php
git commit -m "feat(routing): dispatch fresha auto-connect for instagram-origin links"
```

---

### Task 10: FreshaConnectFetch 'auto' mode

**Files:**
- Modify: `app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php` (mode whitelist `:73-78`, `fetchStorewide()` `:160-250`, constructor `:56-60`)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php:347` (connectFetch closure — verify it resolves via `app()`, which already injects new constructor args)
- Test: `tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`

**Interfaces:**
- Consumes: `FreshaAutoSelector::select()` (Task 5), `connectMode: 'auto'` (Task 9), `config('partna.connect.auto_booking.menu_cache_seconds')` (Task 8).
- Produces: a payload with `selection`, `matchTier`, `raw`, and **no** `connectMode`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Strategies\Fetch\FreshaConnectFetch;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
});

function autoConnectionFor(User $user): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
            'selection' => null,
            'source' => 'instagram',
            'connectMode' => 'auto',
        ],
        'is_active' => true,
    ]);
}

function stubMenu(array $team = []): void
{
    test()->mock(FreshaScraper::class, function (MockInterface $m) use ($team) {
        $m->shouldReceive('fetchMenu')->once()->andReturn([
            'storeName' => 'Anseo Studio',
            'team' => $team,
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });
}

it('writes a storewide selection when nobody matches', function () {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);
    stubMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]);

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['selection']['mode'])->toBe('storewide')
        ->and($next['matchTier'])->toBeNull();
});

it('strips connectMode from the persisted payload on success', function () {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);
    stubMenu();

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    // Leaving it behind strands a pending-window marker forever.
    expect($next)->not->toHaveKey('connectMode');
});

it('still honours the team and storewide modes', function (string $mode) {
    $user = User::factory()->create();
    stubMenu();
    $row = autoConnectionFor($user);
    $row->forceFill(['payload' => [...$row->payload, 'connectMode' => $mode]])->saveQuietly();

    expect(fn () => app(FreshaConnectFetch::class)->fetch($row->fresh()))->not->toThrow(Exception::class);
})->with(['storewide']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`
Expected: FAIL — `FetchShapeException: fresha_mode` (the `'auto'` value is rejected by the whitelist)

- [ ] **Step 3: Write minimal implementation**

Add `FreshaAutoSelector` to the constructor:

```php
    public function __construct(
        private FreshaScraper $scraper,
        private FreshaServiceProjector $projector,
        private FreshaStaffMatcher $staffMatcher,
        private FreshaAutoSelector $autoSelector,
    ) {}
```

Widen the mode whitelist and route `'auto'` to the storewide method:

```php
        $mode = $payload['connectMode'] ?? 'team';
        if (! in_array($mode, ['team', 'storewide', 'auto'], true)) {
            throw new FetchShapeException('fresha_mode');
        }

        // 'auto' is NOT a third branch — it is storewide's scrape and locked
        // projection with the selection decided by FreshaAutoSelector instead of
        // hardcoded. Forking would duplicate the XOR re-assert, the mid-flight
        // disconnect guard, the write-time availability re-check and both
        // lock-timeout catches — the subtle part.
        return match ($mode) {
            'team' => $this->fetchTeam($url, $payload, User::find($connection->user_id)),
            default => $this->fetchStorewide($connection, $url, $payload, auto: $mode === 'auto'),
        };
```

In `fetchStorewide()`, add the parameter, cache the scrape, and swap the selection compose:

```php
    private function fetchStorewide(IntegrationConnection $connection, string $url, array $payload, bool $auto = false): array
```

Replace `$menu = $this->scraper->fetchMenu($url);` with:

```php
            // Two signups at one salon would otherwise scrape the same page twice.
            $menu = Cache::remember(
                'fresha:menu:'.sha1($url),
                (int) config('partna.connect.auto_booking.menu_cache_seconds', 3600),
                fn () => $this->scraper->fetchMenu($url),
            );
```

Replace the `$selection = [...]` block and the return with:

```php
        if ($auto) {
            $chosen = $this->autoSelector->select($user, $menu, $url);
            $selection = $chosen['selection'];
            $rawServices = $chosen['raw'];
            $matchTier = $chosen['matchTier'];
        } else {
            $selection = [
                'url' => $url,
                'storeName' => $menu['storeName'],
                'mode' => 'storewide',
                'employee' => null,
                'services' => $projected['services'],
                'hiddenServiceIds' => $projected['hiddenServiceIds'],
            ];
            $rawServices = $projected['raw'];
            $matchTier = null;
        }

        $next = $payload;
        unset($next['connectPendingAt'], $next['connectMode'], $next['teamMenu']);

        return [
            ...$next,
            'url' => $url,
            'selection' => $selection,
            'raw' => ['services' => $rawServices],
            ...($auto ? ['matchTier' => $matchTier] : []),
        ];
```

In the `$auto` branch the projection happens inside `FreshaAutoSelector::select()`, so skip the existing
`$projected = ...->sync(...)` call when `$auto` is true — guard it with `if (! $auto)` inside the existing
locked closure, leaving every other line of that closure untouched.

Add `use Illuminate\Support\Facades\Cache;` and `use App\Services\Platforms\FreshaAutoSelector;`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`
Expected: PASS

Regression check:
Run: `./vendor/bin/pest --filter=Fresha`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php tests/Feature/Platforms/FreshaAutoConnectFetchTest.php
git commit -m "feat(fresha): add auto connect mode delegating to FreshaAutoSelector"
```

---

### Task 11: FreshaFetch self-heal backstop

Without this, a failed auto-connect launders itself back to `last_refresh_status = 'ok'` on the next
refresh and is never repaired.

**Files:**
- Modify: `app/Services/Platforms/Strategies/Fetch/FreshaFetch.php` (constructor `:26-29`, guard `:36-39`)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php:333-336` (the `new FreshaFetch(...)` closure needs a third argument)
- Test: `tests/Feature/Platforms/FreshaAutoBackstopTest.php`

**Interfaces:**
- Consumes: `FreshaAutoSelector::select()` (Task 5), `connectMode: 'auto'` (Task 9).
- Produces: a populated selection on a previously-failed auto row.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
});

function freshaRow(User $user, array $payload): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => $payload, 'is_active' => true,
    ]);
}

function stubBackstopMenu(): void
{
    test()->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('fetchMenu')->andReturn([
            'storeName' => 'Anseo Studio', 'team' => [],
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });
}

it('repairs a failed auto row instead of 304ing it', function () {
    $user = User::factory()->create();
    stubBackstopMenu();

    $next = app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
        'selection' => null,
        'connectMode' => 'auto',
    ]));

    expect($next['selection']['mode'])->toBe('storewide');
});

it('still 304s a team-mode row awaiting its picker', function () {
    $user = User::factory()->create();

    expect(fn () => app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
        'selection' => null,
    ])))->toThrow(FetchNotModifiedException::class);
});

it('still 304s when there is no url at all', function () {
    $user = User::factory()->create();

    expect(fn () => app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => null, 'selection' => null, 'connectMode' => 'auto',
    ])))->toThrow(FetchNotModifiedException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoBackstopTest.php`
Expected: FAIL — the first test throws `FetchNotModifiedException`

- [ ] **Step 3: Write minimal implementation**

Add the selector to the constructor:

```php
    public function __construct(
        private FreshaScraper $scraper,
        private FreshaServiceProjector $projector,
        private FreshaAutoSelector $autoSelector,
    ) {}
```

Replace the opening guard:

```php
        $payload = SelectionPayload::fromArray($connection->payload ?? []);
        $url = $payload->url;
        $selection = $payload->selection?->toArray();

        if (! $url) {
            throw new FetchNotModifiedException('fresha');
        }

        // SELF-HEAL: a selection-less row is normally "waiting for the user's
        // picker" and correctly 304s. An AUTO row has no picker and no human —
        // if its ConnectFetchJob failed, markTerminal left last_refreshed_at
        // unset, so this sweep picks the row up, 304s it, and
        // PlatformRefresher::recordNotModified writes it back to 'ok'. The row
        // then looks healthy, is serviceless, and is never re-fetched. Repair it
        // here instead. connectMode survives on failure precisely because
        // markTerminal never touches payload.
        if (! is_array($selection) && ($connection->payload['connectMode'] ?? null) === 'auto') {
            $user = User::query()->find($connection->user_id);
            if ($user === null) {
                throw new FetchNotModifiedException('fresha');
            }

            $menu = $this->scraper->fetchMenu($url);
            if ($menu['services'] === []) {
                throw new FetchUnavailableException('fresha_no_services');
            }

            $chosen = $this->autoSelector->select($user, $menu, $url);
            $next = $connection->payload;
            unset($next['connectMode']);

            return [...$next, 'url' => $url, 'selection' => $chosen['selection'],
                'matchTier' => $chosen['matchTier'], 'raw' => ['services' => $chosen['raw']]];
        }

        if (! is_array($selection)) {
            throw new FetchNotModifiedException('fresha');
        }
```

Add `use App\Services\Platforms\FreshaAutoSelector;`.

Update the registry closure in `PlatformRegistryServiceProvider`:

```php
            $r->get('fresha')->fetch(fn () => new FreshaFetch(
                app(FreshaScraper::class),
                app(FreshaServiceProjector::class),
                app(FreshaAutoSelector::class),
            ));
```

Add the matching import to that provider.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoBackstopTest.php`
Expected: PASS (3 tests)

Regression check on the existing refresh suite:
Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaServiceProjectionTest.php tests/Feature/Platforms/FreshaRefreshTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/Strategies/Fetch/FreshaFetch.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/FreshaAutoBackstopTest.php
git commit -m "fix(fresha): self-heal failed auto connects on scheduled refresh

A failed auto ConnectFetchJob left selection null; the refresh sweep then
304d it and recordNotModified wrote it back to 'ok' — permanently
serviceless while reporting healthy."
```

---

### Task 12: End-to-end ordering guard, and the outbound-call audit

The single most valuable test in this plan, plus the acceptance criterion for the whole change.

**Files:**
- Test: `tests/Feature/PreAccount/InstagramFreshaAutoSelectionTest.php`
- Modify: any existing test reaching `seedBooking` that lacks `Http::fake()`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing — this task is verification only.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use App\Services\Profile\PersonNameParser;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
    Http::fake();
});

it('matches the account holder to their salon profile end to end', function () {
    // The name arrives exactly as Instagram supplies it — tagline and all.
    $parsed = PersonNameParser::parse('SIMON DOYLE | Barber & Educator');
    $user = User::factory()->create([
        'account_type' => 'partna',
        'display_name' => $parsed['displayName'],
        'first_name' => $parsed['firstName'],
        'last_name' => $parsed['lastName'],
    ]);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('canonicalUrl')->andReturnUsing(fn (string $u): string => 'https://www.fresha.com/a/anseo-studio-v0v92jna');
        $m->shouldReceive('fetchMenu')->andReturn([
            'storeName' => 'Anseo Studio',
            'team' => [['employeeId' => 'e1', 'displayName' => 'Simon Doyle']],
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn([[
            'serviceId' => 's:9', 'name' => 'Simon Cut', 'duration' => '45min', 'description' => null,
            'price' => 'A$80', 'priceValue' => 80, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]]);
    });

    app(LinkRouter::class)->route(
        $user,
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260',
        new RouteContext(autoConnectBooking: true)
    );

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->fresh()->payload;

    // If the name fold ever moves back below seed(), last_name is null at routing
    // time, the match fails, and this flips to 'storewide'. A structural
    // assertion cannot catch that regression — only this can.
    expect($payload['selection']['mode'])->toBe('employee')
        ->and($payload['matchTier'])->toBe('exact');
});

it('makes no outbound fresha calls anywhere in the suite path', function () {
    $user = User::factory()->create(['account_type' => 'partna']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('canonicalUrl')->andReturn('https://www.fresha.com/a/x');
        $m->shouldReceive('fetchMenu')->andReturn(['storeName' => null, 'team' => [], 'services' => []]);
    });

    app(LinkRouter::class)->route($user, 'https://www.fresha.com/a/x', new RouteContext(autoConnectBooking: true));

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/InstagramFreshaAutoSelectionTest.php`
Expected: FAIL if any earlier task is incomplete. If all of Tasks 1–11 are done, this should PASS on first run — that is the intended outcome and confirms the chain works end to end.

- [ ] **Step 3: Audit the existing suite for live calls**

Find every test that can reach `seedBooking` without faking HTTP:

```bash
grep -rln "LinkRouter\|InstagramAutoSync\|LinkInBioScanJob\|PreAccountBuild" tests/ \
  | xargs grep -Ln "Http::fake\|Queue::fake"
```

For each file listed, add `Http::fake();` to its `beforeEach()`. Under `QUEUE_CONNECTION=sync` the
dispatch runs inline, so an unfaked test would make a real request to fresha.com.

- [ ] **Step 4: Run the full suite**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: PASS, and no test takes an unexplained multi-second pause (the tell for a live outbound call).

Then run static analysis and formatting:

```bash
./vendor/bin/phpstan analyse --memory-limit=2G
php artisan pint
```

Expected: no new PHPStan errors; Pint reports only files this plan touched.

- [ ] **Step 5: Commit**

```bash
git add tests/
git commit -m "test(fresha): end-to-end auto-selection guard and outbound-call audit"
```

---

## Verification checklist

Run before considering the plan complete:

- [ ] `COMPOSER_PROCESS_TIMEOUT=0 composer test` passes
- [ ] `./vendor/bin/phpstan analyse --memory-limit=2G` reports no new errors
- [ ] `php artisan pint` clean on touched files
- [ ] A dashboard paste of a Fresha URL dispatches nothing (Task 9 test 3)
- [ ] A failed auto row is repaired by the refresh, and a team-mode row still 304s (Task 11)
- [ ] `git grep -n "connectMode" app/` shows it stripped on every success path
- [ ] `config('partna.connect.auto_booking.enabled')` set to `false` suppresses all dispatch
- [ ] The global daily cap actually blocks dispatch once spent, and every cache key it writes carries a TTL (`volatile-lru` — a key without one can be evicted, and `Cache::forever` is banned repo-wide)
