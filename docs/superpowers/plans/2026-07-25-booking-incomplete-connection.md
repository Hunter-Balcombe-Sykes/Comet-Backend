# Incomplete Booking Connections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make "this booking connection exists but carries no publishable content" a first-class, machine-readable state, so the dashboard can prompt the owner to finish setup and the public sitepage stops shipping an empty Services page.

**Architecture:** One declarative seam — `PlatformDescriptor::complete(Closure)` / `isComplete(IntegrationConnection)` — modelled on the existing `requiresCapability()` / `availableFor()` pair. Three consumers read it: the dashboard status endpoint, the public page-presence gate, and a backfill command. No migration; the distinguishing data (`payload.selection`, `payload.source`) is already written by the auto-harvest paths and simply never read.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, PostgreSQL via Supabase (tests run SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-07-25-booking-incomplete-connection-design.md`

## Global Constraints

- **Never create Laravel migration files.** The composer guard rejects them. This plan needs no schema change at all.
- **No inline 403 aborts.** CI fails the build on them. Authorization goes through policies; the endpoints here already sit behind `user.api`.
- **Resource classes for API responses** — never return raw Eloquent.
- **Tests run SQLite, production is Postgres.** No DDL changes here, which limits the exposure, but use the shared `setupUsersTable()` / `setupSitesTable()` / `setupServicesTable()` helpers — the `NoLocalCanonicalTableDdl` guard fails the build on locally-declared canonical table DDL.
- **Route-level tests only.** Never call a controller method directly in a test; that antipattern has hidden live bugs in this codebase before.
- **`StrandedPendingWindow::MINUTES = 5` and the deferred-connect state machine are untouched.** Do not write `last_refresh_status='pending'` anywhere in this plan.
- **Comment for WHY, not what.** Brief docblocks on public methods, one line above non-trivial blocks. No paragraphs, no banners.
- `php artisan pint` before each commit; `composer test` green before the final task is marked done.
- Commit messages end with the co-author trailer this repo uses. Do not push; the user commits and merges.

---

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `app/Services/Platforms/Registry/PlatformDescriptor.php` | Owns the `complete()` / `isComplete()` seam | 1 |
| `app/Providers/PlatformRegistryServiceProvider.php` | Declares fresha's (T1) and shop's (T5) completeness predicates | 1, 5 |
| `app/Http/Controllers/Api/Platforms/BookingController.php` | Exposes `setup` on `GET /platforms/booking/status`; fixes the missing `active()` filter | 2 |
| `app/Http/Controllers/Api/Platforms/FreshaController.php` | Read-through team cache on `GET /platforms/fresha/team` | 3 |
| `config/partna.php`, `.env.example` | `platforms.fresha.team_cache_seconds` knob | 3 |
| `app/Services/PublicSite/SiteActionsService.php` | Book-now external action fallback | 4 |
| `app/Services/PublicSite/SitepageDataResolverService.php` | Page-presence gate reads the seam; shop's inline predicate removed | 5 |
| `app/Console/Commands/ReconcileIncompleteBookingCommand.php` | Backfill for already-built accounts | 6 |
| `tests/Unit/Platforms/PlatformCompletenessTest.php` | Seam unit tests | 1 |
| `tests/Feature/Platforms/BookingSetupStateTest.php` | Status-endpoint contract | 2 |
| `tests/Feature/Platforms/FreshaTeamCacheTest.php` | Cache behaviour + key-collision guard | 3 |
| `tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php` | Page presence + Book-now fallback + shop equivalence | 4, 5 |
| `tests/Feature/Platforms/ReconcileIncompleteBookingCommandTest.php` | Command | 6 |

**Task ordering is deliberate.** Task 4 adds a *dormant* fallback action (it only fires when `services` is absent from `$present`); Task 5 then removes `services` for incomplete connections, activating it. Doing 5 before 4 would leave a commit where the booking action vanishes entirely.

---

### Task 1: The `complete()` seam

**Files:**
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php` (add property near `:91`, methods after `availableFor()` at `:547`)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php:349-374` (fresha block)
- Test: `tests/Unit/Platforms/PlatformCompletenessTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PlatformDescriptor::complete(Closure $predicate): self` and `PlatformDescriptor::isComplete(IntegrationConnection $connection): bool`. Tasks 2, 4, 5 and 6 all call `isComplete()`. Resolve descriptors with `app(PlatformRegistry::class)->get($slug)`, which returns `?PlatformDescriptor` — always null-safe the call.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/PlatformCompletenessTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;

function completenessConn(array $payload): IntegrationConnection
{
    return new IntegrationConnection(['platform' => 'fresha', 'payload' => $payload]);
}

it('defaults to complete for a descriptor that never calls complete()', function () {
    $descriptor = PlatformDescriptor::make('square')->label('Square');

    expect($descriptor->isComplete(completenessConn([])))->toBeTrue();
});

it('honours a declared predicate', function () {
    $descriptor = PlatformDescriptor::make('fake')
        ->complete(fn (IntegrationConnection $c): bool => ($c->payload['ready'] ?? false) === true);

    expect($descriptor->isComplete(completenessConn(['ready' => true])))->toBeTrue()
        ->and($descriptor->isComplete(completenessConn(['ready' => false])))->toBeFalse()
        ->and($descriptor->isComplete(completenessConn([])))->toBeFalse();
});

it('treats a fresha row with no selection as incomplete and one with a selection as complete', function () {
    $fresha = app(PlatformRegistry::class)->get('fresha');

    expect($fresha)->not->toBeNull()
        ->and($fresha->isComplete(completenessConn(['url' => 'https://www.fresha.com/a/x', 'selection' => null])))->toBeFalse()
        ->and($fresha->isComplete(completenessConn(['url' => 'https://www.fresha.com/a/x'])))->toBeFalse()
        ->and($fresha->isComplete(completenessConn(['url' => 'https://www.fresha.com/a/x', 'selection' => []])))->toBeTrue()
        ->and($fresha->isComplete(completenessConn([
            'url' => 'https://www.fresha.com/a/x',
            'selection' => ['mode' => 'employee', 'services' => []],
        ])))->toBeTrue();
});

it('leaves square complete regardless of payload — a url IS the whole integration', function () {
    $square = app(PlatformRegistry::class)->get('square');

    expect($square->isComplete(completenessConn([])))->toBeTrue()
        ->and($square->isComplete(completenessConn(['url' => 'https://squareup.com/appointments/book/x'])))->toBeTrue();
});
```

Note the third case: `selection => []` is deliberately **complete**. `SelectionPayload::fromArray` only requires `is_array`, and `FreshaSelectionResource` defaults every key — an empty array is a structurally valid selection. The predicate keys on "is it an array", exactly matching `FreshaFetch.php:37`'s own `is_array($selection)` test, so the two never disagree.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Platforms/PlatformCompletenessTest.php`
Expected: FAIL — `Call to undefined method App\Services\Platforms\Registry\PlatformDescriptor::complete()`

- [ ] **Step 3: Add the seam to PlatformDescriptor**

Add the import alongside the existing `use App\Models\Core\User\User;` at `:5`:

```php
use App\Models\Core\Site\IntegrationConnection;
```

Add the property immediately after `$capabilityGate` (`:90-91`):

```php
/** @var (Closure(IntegrationConnection): bool)|null Optional content predicate consulted by isComplete(). Null = an active row is always complete (every platform's default). */
private ?Closure $completenessGate = null;
```

Add both methods at the end of the class, after `availableFor()` (`:547-550`):

```php
    /**
     * Declare when a connection of this platform carries real, publishable
     * content. Default (no call) = an active row is always complete, which is
     * true for every url-only platform (square, the reservations family) and
     * every platform whose connect writes its payload in full.
     *
     * Opt in only where connect can legitimately leave a row half-built: fresha
     * (auto-harvest from an Instagram bio or Google Business saves a url with no
     * selection — InstagramAutoSync::resolveWrite, GoogleBusinessAutoSync::
     * resolveBookingWrite) and shop (a brand can exist with zero chosen
     * products, FOUND-25).
     *
     * @param  Closure(IntegrationConnection): bool  $predicate
     */
    public function complete(Closure $predicate): self
    {
        $this->completenessGate = $predicate;

        return $this;
    }

    /**
     * Whether this connection has publishable content. Read by the public
     * page-presence gate (SitepageDataResolverService::presentPageIds), the
     * Book-now fallback (SiteActionsService::pool), the dashboard status
     * endpoint (BookingController::statusFor) and the reconcile command.
     *
     * A predicate MAY query — shop's does — so callers on the public render
     * path wrap it in safeQuery() and fail CLOSED (hide the page), matching the
     * posture the inline shop gate had before this seam existed.
     *
     * NOT consulted by PublicIntegrationController: an incomplete fresha row's
     * url is the Book-now destination, so the row must keep reaching the
     * sitepage renderer. See the spec's "Decided" section before wiring this
     * into filterPayload().
     */
    public function isComplete(IntegrationConnection $connection): bool
    {
        return $this->completenessGate === null || ($this->completenessGate)($connection);
    }
```

- [ ] **Step 4: Declare fresha's predicate**

In `app/Providers/PlatformRegistryServiceProvider.php`, add the import if absent:

```php
use App\Models\Core\Site\IntegrationConnection;
```

Then immediately after the fresha `connectFetchError(...)` line (`:374`), add:

```php
            // An auto-harvested fresha row (Instagram bio / Google Business) is
            // {url, selection: null} — connected, but with no service menu to
            // render. FreshaFetch 304s it forever (:36-39), so it can never
            // self-heal; only the owner picking a team member completes it.
            // is_array (not !== null) mirrors FreshaFetch's own guard exactly so
            // the two predicates cannot drift.
            $r->get('fresha')->complete(fn (IntegrationConnection $c): bool => is_array($c->payload['selection'] ?? null));
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Platforms/PlatformCompletenessTest.php`
Expected: PASS (4 tests)

Then confirm no registry guard regressed:

Run: `php artisan test tests/Feature/Platforms/ --filter=Registry`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Platforms/Registry/PlatformDescriptor.php app/Providers/PlatformRegistryServiceProvider.php tests/Unit/Platforms/PlatformCompletenessTest.php
git add app/Services/Platforms/Registry/PlatformDescriptor.php app/Providers/PlatformRegistryServiceProvider.php tests/Unit/Platforms/PlatformCompletenessTest.php
git commit -m "feat(platforms): add complete() completeness seam to PlatformDescriptor"
```

---

### Task 2: `setup` block on `GET /platforms/booking/status`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/BookingController.php:133-175` (`statusFor`)
- Test: `tests/Feature/Platforms/BookingSetupStateTest.php`

**Interfaces:**
- Consumes: `PlatformDescriptor::isComplete()` from Task 1.
- Produces: the response contract `setup: {complete: bool, reason: ?string, seededFrom: ?string, seededAt: ?string}` — `setup` is `null` when `connected` is false. No later task depends on this.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/BookingSetupStateTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function setupStateUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function seedFresha(User $user, array $payload, bool $isActive = true): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => $payload,
        'is_active' => $isActive,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

it('reports a harvested fresha row as connected but incomplete, naming the source', function () {
    $user = setupStateUser('harvested');
    seedFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'source' => 'instagram',
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('provider', 'fresha')
        ->assertJsonPath('url', 'https://www.fresha.com/a/anseo-studio')
        ->assertJsonPath('setup.complete', false)
        ->assertJsonPath('setup.reason', 'awaiting_selection')
        ->assertJsonPath('setup.seededFrom', 'instagram');
});

it('reports a google-seeded fresha row with its own source', function () {
    $user = setupStateUser('gbseeded');
    seedFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'source' => 'google-business',
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('setup.complete', false)
        ->assertJsonPath('setup.seededFrom', 'google-business');
});

it('reports a completed fresha row as complete with a null reason', function () {
    $user = setupStateUser('completed');
    seedFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => [
            'url' => 'https://www.fresha.com/a/anseo-studio',
            'storeName' => 'Anseo Studio',
            'mode' => 'employee',
            'employee' => ['employeeId' => 'e1', 'displayName' => 'Simon'],
            'services' => [],
            'hiddenServiceIds' => [],
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('name', 'Anseo Studio')
        ->assertJsonPath('setup.complete', true)
        ->assertJsonPath('setup.reason', null)
        ->assertJsonPath('setup.seededFrom', null);
});

it('treats a staff-disabled fresha row as not connected', function () {
    $user = setupStateUser('disabled');
    seedFresha($user, ['url' => 'https://www.fresha.com/a/x', 'selection' => null], isActive: false);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('provider', null)
        ->assertJsonPath('setup', null);
});

it('reports a square row as complete — a url is the whole integration', function () {
    $user = setupStateUser('squared');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'square',
        'resource_id' => 'square',
        'payload' => ['url' => 'https://squareup.com/appointments/book/abc'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('provider', 'square')
        ->assertJsonPath('setup.complete', true);
});

it('returns setup null when nothing is connected', function () {
    actingAsUser(setupStateUser('empty'))->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('setup', null);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Platforms/BookingSetupStateTest.php`
Expected: FAIL — `Unable to find JSON path 'setup.complete'`, and the staff-disabled case fails with `connected` still true.

- [ ] **Step 3: Rewrite `statusFor`**

In `app/Http/Controllers/Api/Platforms/BookingController.php`, add the import:

```php
use App\Services\Platforms\Registry\PlatformRegistry;
```

Replace the whole `statusFor` method (`:133-175`) with:

```php
    /**
     * Aggregate connected-state across the booking-family connections. Priority
     * fresha > square > custom (only one is ever connected — single slot).
     *
     * `setup` distinguishes "connected AND usable" from "connected but still
     * awaiting the owner" — an auto-harvested fresha row is {url, selection:null}
     * and renders nothing. Null when nothing is connected. This is the only
     * signal the dashboard has; /fresha/selection reveals the same state but is
     * not called from the Booking page's mount path.
     *
     * @return array{connected:bool, provider:?string, name:?string, url:?string, setup:?array{complete:bool, reason:?string, seededFrom:?string, seededAt:?string}}
     */
    private function statusFor(User $user): array
    {
        // active() on both provider lookups: a staff-disabled row is invisible
        // publicly (every read path filters is_active), so reporting it as
        // connected here left the dashboard contradicting the live site.
        $fresha = $user->integrationConnections()->where('platform', Platform::Fresha->value)->active()->first();
        if ($fresha) {
            $sel = SelectionPayload::fromArray($fresha->payload);

            return [
                'connected' => true,
                'provider' => 'fresha',
                'name' => $sel->selection?->storeName(),
                'url' => $sel->url,
                'setup' => $this->setupFor($fresha, $sel->source),
            ];
        }

        $square = $user->integrationConnections()->where('platform', Platform::Square->value)->active()->first();
        if ($square) {
            return [
                'connected' => true,
                'provider' => 'square',
                'name' => null,
                'url' => SelectionPayload::fromArray($square->payload)->url,
                'setup' => $this->setupFor($square, SelectionPayload::fromArray($square->payload)->source),
            ];
        }

        $custom = CardPayload::fromArray($this->readConnection($user));
        if ($custom->provider() === 'custom') {
            return [
                'connected' => true,
                'provider' => 'custom',
                'name' => $custom->name(),
                'url' => $custom->url(),
                'setup' => ['complete' => true, 'reason' => null, 'seededFrom' => null, 'seededAt' => null],
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null, 'setup' => null];
    }

    /**
     * `reason` is a string rather than a bool so a second incompleteness cause
     * is additive. `seededFrom` is payload.source — written by the two
     * auto-harvest services, absent on every user-initiated connect — so the
     * prompt can say "we found this on your Instagram" instead of a generic nag.
     *
     * @return array{complete:bool, reason:?string, seededFrom:?string, seededAt:?string}
     */
    private function setupFor(IntegrationConnection $connection, ?string $source): array
    {
        $complete = app(PlatformRegistry::class)
            ->get(strtolower((string) $connection->platform))
            ?->isComplete($connection) ?? true;

        return [
            'complete' => $complete,
            'reason' => $complete ? null : 'awaiting_selection',
            'seededFrom' => $complete ? null : $source,
            'seededAt' => $complete ? null : $connection->created_at?->toIso8601String(),
        ];
    }
```

Add the model import if not already present:

```php
use App\Models\Core\Site\IntegrationConnection;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/BookingSetupStateTest.php`
Expected: PASS (6 tests)

Then the existing suite for this controller, which must not regress:

Run: `php artisan test tests/Feature/Platforms/BookingControllerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/BookingController.php tests/Feature/Platforms/BookingSetupStateTest.php
git add app/Http/Controllers/Api/Platforms/BookingController.php tests/Feature/Platforms/BookingSetupStateTest.php
git commit -m "feat(booking): expose setup state on booking/status and filter inactive rows"
```

---

### Task 3: Read-through team cache on `GET /platforms/fresha/team`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/FreshaController.php:331-347` (`team`)
- Modify: `config/partna.php`, `.env.example`
- Test: `tests/Feature/Platforms/FreshaTeamCacheTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks depend on. Response shape is unchanged — `{url, storeName, team[], services[]}`.

**⚠ Trap — do NOT reuse the `teamMenu` payload key.** `FreshaController::connectStatus` discriminates connect mode with `$isTeam = ($payload['connectMode'] ?? null) === 'team' || is_array($payload['teamMenu'] ?? null);`. Writing `teamMenu` from this cache would make a completed **storewide** connection report `mode: 'team'` on the next `/connect/status` poll. Use `teamMenuCache` / `teamMenuCachedAt`, which nothing else reads.

- [ ] **Step 1: Add the config knob**

In `config/partna.php`, beside the other fresha settings (`refresh.intervals.fresha` is around `:1560`), add a `platforms.fresha` block — if a `platforms` key already exists, nest into it rather than creating a second:

```php
    'platforms' => [
        'fresha' => [
            // How long a scraped team roster stays servable without re-scraping.
            // The dashboard prompt for an unfinished connection can open once per
            // session; a live scrape per open would hammer fresha.com for a list
            // that changes when someone joins or leaves a salon.
            'team_cache_seconds' => (int) env('PARTNA_FRESHA_TEAM_CACHE_SECONDS', 86400),
        ],
    ],
```

In `.env.example`, near the other `PARTNA_REFRESH_INTERVAL_*` entries:

```
# Seconds a scraped Fresha team roster is served from payload.teamMenuCache before re-scraping (default 86400).
# PARTNA_FRESHA_TEAM_CACHE_SECONDS=86400
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Platforms/FreshaTeamCacheTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config()->set('partna.platforms.fresha.team_cache_seconds', 86400);
});

function teamCacheUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function seedTeamCacheFresha(User $user, array $payload): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

/** Minimal __NEXT_DATA__ page the scraper can parse. */
function freshaPageHtml(string $employeeName = 'Simon'): string
{
    $data = json_encode(['props' => ['pageProps' => ['data' => ['location' => [
        'name' => 'Anseo Studio',
        'employeeProfiles' => ['edges' => [
            ['node' => ['employeeId' => 'e1', 'displayName' => $employeeName, 'jobTitle' => 'Barber']],
        ]],
        'services' => [],
    ]]]]]);

    return '<html><script id="__NEXT_DATA__" type="application/json">'.$data.'</script></html>';
}

it('scrapes on a cold cache and stores the roster under teamMenuCache', function () {
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('coldcache');
    $row = seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('storeName', 'Anseo Studio')
        ->assertJsonPath('team.0.displayName', 'Simon');

    $payload = $row->fresh()->payload;
    expect($payload['teamMenuCache']['storeName'])->toBe('Anseo Studio')
        ->and($payload['teamMenuCachedAt'])->toBeString()
        ->and($payload['url'])->toBe('https://www.fresha.com/a/anseo-studio')
        ->and($payload)->not->toHaveKey('teamMenu');
});

it('serves the second call from cache without scraping again', function () {
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('warmcache');
    seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')->assertOk();
    $afterFirst = Http::recorded()->count();

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('team.0.displayName', 'Simon');

    expect(Http::recorded()->count())->toBe($afterFirst);
});

it('re-scrapes when the cache is older than the TTL', function () {
    Http::fake(['*' => Http::response(freshaPageHtml('Rotated'), 200)]);
    $user = teamCacheUser('staleche');
    seedTeamCacheFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'teamMenuCache' => ['storeName' => 'Old', 'team' => [], 'services' => []],
        'teamMenuCachedAt' => now()->subDays(3)->toIso8601String(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('team.0.displayName', 'Rotated');
});

it('re-scrapes on ?refresh=1 even with a warm cache', function () {
    Http::fake(['*' => Http::response(freshaPageHtml('Fresh'), 200)]);
    $user = teamCacheUser('forcedref');
    seedTeamCacheFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'teamMenuCache' => ['storeName' => 'Old', 'team' => [], 'services' => []],
        'teamMenuCachedAt' => now()->toIso8601String(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team?refresh=1')
        ->assertOk()
        ->assertJsonPath('team.0.displayName', 'Fresh');
});

it('serves a stale cache when the scrape fails rather than 502ing', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    $user = teamCacheUser('stalefall');
    seedTeamCacheFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'teamMenuCache' => ['storeName' => 'Cached Studio', 'team' => [], 'services' => []],
        'teamMenuCachedAt' => now()->subDays(3)->toIso8601String(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('storeName', 'Cached Studio');
});

it('still 404s when no url is connected', function () {
    actingAsUser(teamCacheUser('nourl'))->getJson('/api/platforms/fresha/team')
        ->assertStatus(404);
});

it('does not purge the sitepage cache when caching the roster', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('nopurge');
    seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')->assertOk();

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});
```

Add these imports at the top of the test file:

```php
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\Queue;
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Platforms/FreshaTeamCacheTest.php`
Expected: FAIL — the cache assertions fail because `teamMenuCache` is never written and every call re-scrapes.

- [ ] **Step 4: Implement the read-through cache**

In `app/Http/Controllers/Api/Platforms/FreshaController.php` add the import:

```php
use Illuminate\Support\Carbon;
```

Replace the `team` method (`:331-347`) with:

```php
    // GET /api/platforms/fresha/team — team + services for the saved URL.
    // Read-through cached into payload.teamMenuCache: the dashboard's
    // finish-setup prompt can open once per session, and a live scrape per open
    // would hammer fresha.com for a roster that changes only when staff join or
    // leave. ?refresh=1 forces a re-scrape.
    //
    // The cache key is deliberately NOT 'teamMenu' — connectStatus() uses
    // is_array(payload.teamMenu) to discriminate team from storewide mode, so
    // writing that key here would make a completed storewide connection report
    // mode:'team' on its next poll.
    public function team(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $payload = $this->readConnection($user) ?? [];
        $url = SelectionPayload::fromArray($payload)->url;
        if (! $url) {
            return $this->error('No Fresha URL connected yet. POST one to /connect first.', 404);
        }

        $cached = is_array($payload['teamMenuCache'] ?? null) ? $payload['teamMenuCache'] : null;
        if ($cached !== null && ! $request->boolean('refresh') && $this->teamCacheIsFresh($payload)) {
            return $this->success(['url' => $url, ...$cached]);
        }

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        try {
            $menu = $this->budget->open($seconds, fn () => $this->scraper->fetchMenu($url));
        } catch (SafeUrlException|ConnectionException|HttpException) {
            // A stale roster beats a dead picker — the owner can still identify
            // themselves, and saveSelection() re-scrapes server-side anyway, so
            // a departed employee 404s correctly at submit time.
            if ($cached !== null) {
                return $this->success(['url' => $url, ...$cached]);
            }
            abort(502, 'Could not reach Fresha — please try again.');
        }

        // Quiet, merged write. saveQuietly() because IntegrationConnectionObserver
        // ::saved() purges the Cloudflare sitepage cache on ANY payload change —
        // and this roster is private dashboard data that appears nowhere in the
        // public payload, so a purge here would be pure waste. Merged so
        // url/selection/raw ride through untouched. Never writeConnection(pending:)
        // — this must not move last_refresh_status.
        $row = $this->connectionFor($user);
        if ($row !== null) {
            $row->payload = [...($row->payload ?? []), 'teamMenuCache' => $menu, 'teamMenuCachedAt' => now()->toIso8601String()];
            $row->saveQuietly();
        }

        return $this->success(['url' => $url, ...$menu]);
    }

    /** Whether payload.teamMenuCachedAt is inside the configured TTL. */
    private function teamCacheIsFresh(array $payload): bool
    {
        $at = $payload['teamMenuCachedAt'] ?? null;
        if (! is_string($at)) {
            return false;
        }

        $ttl = (int) config('partna.platforms.fresha.team_cache_seconds', 86400);

        return Carbon::parse($at)->addSeconds($ttl)->isFuture();
    }
```

Add the import for the scraper's 502 path if not already present:

```php
use Symfony\Component\HttpKernel\Exception\HttpException;
```

`FreshaScraper::fetchLocation` calls `abort(502, …)` on a non-200 or missing `__NEXT_DATA__`, which raises `HttpException` — catching it is what makes the stale-fallback test pass.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/FreshaTeamCacheTest.php`
Expected: PASS (6 tests)

Then prove the connect-status discriminator is untouched:

Run: `php artisan test tests/Feature/Platforms/FreshaAsyncConnectTest.php tests/Feature/Platforms/FreshaStorewideDeferredConnectTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/FreshaController.php config/partna.php tests/Feature/Platforms/FreshaTeamCacheTest.php
git add app/Http/Controllers/Api/Platforms/FreshaController.php config/partna.php .env.example tests/Feature/Platforms/FreshaTeamCacheTest.php
git commit -m "perf(fresha): read-through cache the team roster on GET /fresha/team"
```

---

### Task 4: Book-now fallback action (dormant until Task 5)

**Files:**
- Modify: `app/Services/PublicSite/SiteActionsService.php:103-146`
- Test: `tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php` (created here, extended in Task 5)

**Interfaces:**
- Consumes: `PlatformDescriptor::isComplete()` from Task 1.
- Produces: `SiteActionsService::incompleteBookingUrl(array $connectionsByPlatform): ?string` — private, no later task calls it.

This task adds a branch that cannot fire yet: it is reached only when `services` is absent from `$present`, which Task 5 causes. Ship it first so no intermediate commit drops the booking action.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php`. The first test asserts today's behaviour (proving the fallback is correctly dormant); the second asserts the new helper directly through the public endpoint once Task 5 lands — so **only the first two tests below are written now**, and Task 5 adds the rest.

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
});

function bookingPresenceUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'status' => 'active',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function bookingPresenceSite(User $user): Site
{
    return Site::create([
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'is_published' => true,
    ]);
}

function seedPresenceFresha(User $user, ?array $selection): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/anseo-studio',
            'selection' => $selection,
            'source' => 'instagram',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

it('keeps the services page action for a fresha row that HAS a selection', function () {
    $user = bookingPresenceUser('hassel');
    bookingPresenceSite($user);
    seedPresenceFresha($user, ['mode' => 'employee', 'services' => [], 'hiddenServiceIds' => []]);

    $res = $this->getJson("/api/public/profiles/{$user->handle}")->assertOk();

    expect($res->json('pageOrder'))->toContain('services');

    $action = collect($res->json('rankedActions'))->firstWhere('id', 'booking-services');
    expect($action)->not->toBeNull()
        ->and($action['kind'])->toBe('page')
        ->and($action['pageId'])->toBe('services');
});

it('does not emit a duplicate booking action when the services page is present', function () {
    $user = bookingPresenceUser('nodupes');
    bookingPresenceSite($user);
    seedPresenceFresha($user, ['mode' => 'employee', 'services' => [], 'hiddenServiceIds' => []]);

    $res = $this->getJson("/api/public/profiles/{$user->handle}")->assertOk();

    expect(collect($res->json('rankedActions'))->where('id', 'booking-services'))->toHaveCount(1);
});
```

- [ ] **Step 2: Run test to verify current behaviour holds**

Run: `php artisan test tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php`
Expected: PASS — these two pin behaviour that must survive Tasks 4 and 5.

- [ ] **Step 3: Add the fallback branch**

In `app/Services/PublicSite/SiteActionsService.php`, add the import:

```php
use App\Services\Platforms\Registry\PlatformRegistry;
```

The connection preload at `:103-110` selects a column list that omits `user_id`. A completeness predicate may need it (shop's queries by user), so widen it:

```php
                ->get(['id', 'user_id', 'platform', 'resource_id', 'payload', 'created_at']) as $conn
```

Extend the booking branch at `:137-146` with a third arm:

```php
        // D1: services page present → page action to /services. Else, when a
        // booking link/provider is live, external action straight to that URL.
        // Never both — an owner with both sees the richer /services page.
        if (isset($present['services'])) {
            $out[] = $this->entry('booking-services', 'page', ActionVocabulary::labelFor('booking-services'), pageId: 'services');
        } elseif (($booking['state'] ?? null) === 'live') {
            $url = $this->safeHref($booking['data']['resolved_url'] ?? null);
            if ($url !== null) {
                $out[] = $this->entry('booking-services', 'external', ActionVocabulary::labelFor('booking-services'), url: $url);
            }
        } elseif (($url = $this->incompleteBookingUrl($connectionsByPlatform)) !== null) {
            $out[] = $this->entry('booking-services', 'external', ActionVocabulary::labelFor('booking-services'), url: $url);
        }
```

Add the helper beside the other private helpers:

```php
    /**
     * The harvested URL of a booking connection that exists but has no
     * publishable content yet — an auto-seeded fresha row is {url,
     * selection:null}, so its Services page is empty and presentPageIds()
     * withholds it. The link itself is real and works, so visitors keep a
     * working Book-now rather than losing the action entirely while the owner
     * finishes setup.
     *
     * Fresha only: square's connect stores a url and nothing else, so a square
     * row is complete by definition and never reaches this path.
     *
     * @param  array<string, list<IntegrationConnection>>  $connectionsByPlatform
     */
    private function incompleteBookingUrl(array $connectionsByPlatform): ?string
    {
        $descriptor = app(PlatformRegistry::class)->get('fresha');
        if ($descriptor === null) {
            return null;
        }

        foreach ($connectionsByPlatform['fresha'] ?? [] as $conn) {
            if (! $descriptor->isComplete($conn)) {
                return $this->safeHref($this->connectionPayload($conn)['url'] ?? null);
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run tests to verify nothing regressed**

Run: `php artisan test tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php`
Expected: PASS (2 tests) — the new branch is dormant, so behaviour is unchanged.

Run: `php artisan test tests/Feature/PublicSite/`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/PublicSite/SiteActionsService.php tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php
git add app/Services/PublicSite/SiteActionsService.php tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php
git commit -m "feat(publicsite): add Book-now fallback action for incomplete booking connections"
```

---

### Task 5: Page-presence gate reads the seam; shop's inline predicate consolidated

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php:189-250`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (shop descriptor)
- Test: `tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php` (extend)

**Interfaces:**
- Consumes: `PlatformDescriptor::isComplete()` from Task 1; the dormant fallback from Task 4, which this task activates.
- Produces: nothing later tasks depend on.

**⚠ This task moves scarred code.** The shop predicate at `:234-248` carries a comment warning that its `whereNull()->orWhere()` NULL-safety is required (`!= 'pending'` alone is wrong because `NULL != 'pending'` is NULL in SQL) and that it must stay in lockstep with `PublicIntegrationConnectionResource::filterPayload`. Move it **verbatim**. Do not simplify it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php`:

```php
it('withholds the services page and emits an external Book-now for a selection-less fresha row', function () {
    $user = bookingPresenceUser('nosel');
    bookingPresenceSite($user);
    seedPresenceFresha($user, null);

    $res = $this->getJson("/api/public/profiles/{$user->handle}")->assertOk();

    expect($res->json('pageOrder'))->not->toContain('services');

    $action = collect($res->json('rankedActions'))->firstWhere('id', 'booking-services');
    expect($action)->not->toBeNull()
        ->and($action['kind'])->toBe('external')
        ->and($action['pageId'])->toBeNull()
        ->and($action['url'])->toBe('https://www.fresha.com/a/anseo-studio');
});

it('still exposes the harvested url on /integrations so the renderer can build the button', function () {
    $user = bookingPresenceUser('intact');
    bookingPresenceSite($user);
    seedPresenceFresha($user, null);

    $this->getJson("/api/public/profiles/{$user->handle}/integrations")
        ->assertOk()
        ->assertJsonPath('data.platforms.fresha.0.payload.url', 'https://www.fresha.com/a/anseo-studio')
        ->assertJsonPath('data.platforms.fresha.0.payload.selection', null);
});

it('restores the services page once a selection is saved', function () {
    $user = bookingPresenceUser('restored');
    bookingPresenceSite($user);
    $row = seedPresenceFresha($user, null);

    expect($this->getJson("/api/public/profiles/{$user->handle}")->json('pageOrder'))->not->toContain('services');

    $row->update(['payload' => [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => ['mode' => 'employee', 'services' => [], 'hiddenServiceIds' => []],
    ]]);

    expect($this->getJson("/api/public/profiles/{$user->handle}")->json('pageOrder'))->toContain('services');
});

it('does not gate a platform that never declares completeness', function () {
    $user = bookingPresenceUser('ungated');
    bookingPresenceSite($user);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'youtube',
        'resource_id' => 'youtube',
        'payload' => ['url' => 'https://youtube.com/@someone'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    expect($this->getJson("/api/public/profiles/{$user->handle}")->json('pageOrder'))->toContain('watch');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php`
Expected: FAIL — `services` is still in `pageOrder` for the selection-less row, and `booking-services` is still `kind: page`.

- [ ] **Step 3: Declare shop's predicate on its descriptor**

In `app/Providers/PlatformRegistryServiceProvider.php`, find the `shop` descriptor registration and add, immediately after it:

```php
            // FOUND-25 + W9: a shop connection's payload is a static lifecycle
            // marker — brands/products live relationally and are decoupled from
            // connect (addBrand stores a brand with zero products). An active
            // connection alone isn't real content.
            //
            // MUST stay in lockstep with PublicIntegrationConnectionResource::
            // filterPayload(), which rejects a connect_status='pending' brand:
            // without the exclusion, page-presence says shop is present while the
            // payload ships empty. `!= 'pending'` alone is WRONG — NULL !=
            // 'pending' is NULL (falsy) in SQL, which would exclude every settled
            // (NULL) brand too; the whereNull()->orWhere() is required.
            $r->get('shop')->complete(fn (IntegrationConnection $c): bool => ShopProduct::query()
                ->whereHas('brand', fn ($q) => $q
                    ->where(fn ($q3) => $q3->whereNull('connect_status')->orWhere('connect_status', '<>', 'pending'))
                    ->whereHas('connection', fn ($q2) => $q2->where('user_id', $c->user_id)))
                ->exists());
```

Add the import:

```php
use App\Models\Core\Site\ShopProduct;
```

- [ ] **Step 4: Rewrite the presence loop**

In `app/Services/PublicSite/SitepageDataResolverService.php`, add the import:

```php
use App\Services\Platforms\Registry\PlatformRegistry;
```

Replace lines `189-250` (the `if ($userId !== null) {` block's connection section, from the `$platforms = $this->safeQuery(` assignment through `$present[$page] = true;` and its closing brace) with:

```php
            if ($userId !== null) {
                // Active integration connections → platform pages. Read
                // defensively (safeQuery) so a missing table in a partial test env
                // or a DB blip degrades to "signal absent" rather than a 500 —
                // same resilience posture as AnalyticsQueryService.
                //
                // Full rows, not distinct platform strings: the completeness
                // predicate below takes the connection.
                $connections = $this->safeQuery(
                    fn () => IntegrationConnection::query()
                        ->where('user_id', $userId)
                        ->where('is_active', true)
                        ->get(['id', 'user_id', 'platform', 'payload'])
                        ->all(),
                    [],
                    'active_integration_connections',
                    $site,
                );

                $registry = app(PlatformRegistry::class);

                foreach ($connections as $conn) {
                    $platform = strtolower((string) $conn->platform);
                    $page = self::PLATFORM_TO_PAGE[$platform] ?? null;
                    if ($page === null || isset($present[$page])) {
                        continue;
                    }

                    // An active connection is not automatically real content —
                    // shop needs a chosen product, fresha needs a saved selection.
                    // Declared per-platform on the descriptor (PlatformDescriptor::
                    // complete()); every other platform returns true without
                    // touching the DB. Fails CLOSED on a query error, matching the
                    // posture the inline shop gate had before this seam existed.
                    $descriptor = $registry->get($platform);
                    if ($descriptor !== null && ! $this->safeQuery(
                        fn () => $descriptor->isComplete($conn),
                        false,
                        "platform_complete_{$platform}",
                        $site,
                    )) {
                        continue;
                    }

                    $present[$page] = true;
                }
            }
```

Remove the now-unused `ShopProduct` import from this file if nothing else references it — check with `grep -n ShopProduct app/Services/PublicSite/SitepageDataResolverService.php` before deleting.

Update the docblock at `:164-166` — the line reading `'shop' additionally requires a chosen ShopProduct (FOUND-25 — connecting a brand alone stores zero products)` becomes:

```
     *   platforms— active integration connections + link blocks (PLATFORM_TO_PAGE),
     *              each filtered by its descriptor's completeness predicate
     *              (PlatformDescriptor::isComplete — shop needs a chosen product,
     *              fresha a saved selection)
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php`
Expected: PASS (6 tests)

Now prove shop did not regress. Find its existing coverage and run it:

Run: `php artisan test tests/Feature/PublicSite/ tests/Feature/Shop/ --filter=shop`
Expected: PASS. If any shop page-presence test fails, the predicate was not moved verbatim — diff it against `git show HEAD~1:app/Services/PublicSite/SitepageDataResolverService.php` before changing anything else.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/PublicSite/SitepageDataResolverService.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php
git add app/Services/PublicSite/SitepageDataResolverService.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php
git commit -m "fix(publicsite): gate platform page presence on descriptor completeness"
```

---

### Task 6: `booking:reconcile-incomplete` backfill command

**Files:**
- Create: `app/Console/Commands/ReconcileIncompleteBookingCommand.php`
- Test: `tests/Feature/Platforms/ReconcileIncompleteBookingCommandTest.php`

**Interfaces:**
- Consumes: `PlatformDescriptor::isComplete()` from Task 1.
- Produces: the console signature `booking:reconcile-incomplete {--apply} {--invalidate}`. Nothing depends on it.

Needed because the refresh cron skips these rows by design (`FreshaFetch` 304s a selection-less row), so already-built accounts — including `simondoyle` — never pick up the Task 5 gate on their cached sitepage.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/ReconcileIncompleteBookingCommandTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function reconcileUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function reconcileFresha(User $user, ?array $selection, bool $isActive = true): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => $selection, 'source' => 'instagram'],
        'is_active' => $isActive,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

it('lists incomplete rows and changes nothing by default', function () {
    $user = reconcileUser('incompl');
    $row = reconcileFresha($user, null);
    $before = $row->updated_at;

    $this->artisan('booking:reconcile-incomplete')
        ->expectsOutputToContain('incompl')
        ->expectsOutputToContain('1 incomplete')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect($row->fresh()->updated_at->eq($before))->toBeTrue();
});

it('ignores complete rows', function () {
    reconcileFresha(reconcileUser('complete'), ['mode' => 'employee', 'services' => []]);

    $this->artisan('booking:reconcile-incomplete')
        ->expectsOutputToContain('0 incomplete')
        ->assertExitCode(0);
});

it('ignores inactive rows', function () {
    reconcileFresha(reconcileUser('inactive'), null, isActive: false);

    $this->artisan('booking:reconcile-incomplete')
        ->expectsOutputToContain('0 incomplete')
        ->assertExitCode(0);
});

it('never writes last_refresh_status pending', function () {
    $row = reconcileFresha(reconcileUser('nopend'), null);

    $this->artisan('booking:reconcile-incomplete --apply')->assertExitCode(0);

    expect($row->fresh()->last_refresh_status)->toBe('ok');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Platforms/ReconcileIncompleteBookingCommandTest.php`
Expected: FAIL — `Command "booking:reconcile-incomplete" is not defined.`

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/ReconcileIncompleteBookingCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Console\Command;

/**
 * Reports booking connections that are active but carry no publishable content
 * — an auto-harvested fresha row is {url, selection: null}.
 *
 * Not scheduled. These rows are invisible to the refresh cron by design
 * (FreshaFetch 304s a selection-less row), so an account built before the
 * completeness gate shipped keeps a cached sitepage advertising an empty
 * Services page until this runs with --invalidate.
 *
 * Dry run by default: --apply is required to touch anything.
 */
class ReconcileIncompleteBookingCommand extends Command
{
    protected $signature = 'booking:reconcile-incomplete
                            {--apply : Perform writes instead of reporting only}
                            {--invalidate : Also purge the sitepage cache for each affected user}';

    protected $description = 'Report (and optionally re-cache) booking connections awaiting owner setup';

    public function handle(PlatformRegistry $registry): int
    {
        $descriptor = $registry->get('fresha');
        if ($descriptor === null) {
            $this->error('No fresha descriptor registered.');

            return self::FAILURE;
        }

        $incomplete = IntegrationConnection::query()
            ->where('platform', 'fresha')
            ->where('is_active', true)
            ->with('user:id,handle')
            ->get()
            ->reject(fn (IntegrationConnection $c): bool => $descriptor->isComplete($c));

        foreach ($incomplete as $connection) {
            $this->line(sprintf(
                '  %s  seeded=%s  url=%s',
                $connection->user?->handle ?? $connection->user_id,
                $connection->payload['source'] ?? 'user',
                $connection->payload['url'] ?? '(none)',
            ));
        }

        $this->info("{$incomplete->count()} incomplete booking connection(s).");

        if (! $this->option('apply')) {
            $this->comment('Dry run — pass --apply to act.');

            return self::SUCCESS;
        }

        if ($this->option('invalidate')) {
            foreach ($incomplete as $connection) {
                if ($connection->user !== null) {
                    // Task 5 changed page_order for these sites; a published
                    // sitepage keeps serving the stale order until it re-renders.
                    $this->invalidate($connection);
                }
            }
            $this->info('Sitepage caches invalidated.');
        }

        return self::SUCCESS;
    }

    /**
     * Same path IntegrationConnectionObserver::saved() takes on a payload change,
     * called directly because this command changes no payload — only the
     * page_order these rows produce changed, under its feet, when the
     * completeness gate shipped.
     */
    private function invalidate(IntegrationConnection $connection): void
    {
        $this->refresher->refresh($connection);
    }
}
```

Inject the refresher via the constructor:

```php
    public function __construct(
        private readonly IntegrationConnectionCacheRefresher $refresher = new IntegrationConnectionCacheRefresher,
    ) {
        parent::__construct();
    }
```

with the import:

```php
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
```

The defaulted argument mirrors `IntegrationConnectionObserver`'s own constructor — the refresher has no dependencies, so the default is always safe.

- [ ] **Step 4: Confirm the selection write already invalidates**

No code change expected here, but verify rather than assume — completing a selection also changes `page_order`, so a missing invalidation would leave the sitepage stale right after the owner finishes setup.

`IntegrationConnectionObserver::saved()` (`app/Observers/Core/IntegrationConnectionObserver.php:43-53`) already calls `$this->refresher->refresh($connection)` whenever `wasRecentlyCreated || wasChanged('payload') || wasChanged('display_settings') || wasChanged('is_active')`. `FreshaController::saveSelection` writes the payload through `writeConnection` → `updateOrCreate`, so the observer fires and the purge happens.

Confirm with a quick assertion appended to `tests/Feature/Platforms/BookingSetupStateTest.php`:

```php
it('purges the sitepage cache when a payload write completes a connection', function () {
    Queue::fake();
    $user = setupStateUser('purgeonsel');
    $row = seedFresha($user, ['url' => 'https://www.fresha.com/a/x', 'selection' => null]);

    $row->payload = ['url' => 'https://www.fresha.com/a/x', 'selection' => ['mode' => 'employee', 'services' => []]];
    $row->save();

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
```

with imports `use App\Jobs\Cloudflare\CloudflareCachePurgeJob;` and `use Illuminate\Support\Facades\Queue;`. If this fails, stop and report — the invalidation assumption in the spec is wrong and Task 6's `--invalidate` is doing more work than believed.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/ReconcileIncompleteBookingCommandTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Full suite**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test`
Expected: PASS. Do not pipe the output — piping masks the exit code in this repo.

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Console/Commands/ReconcileIncompleteBookingCommand.php tests/Feature/Platforms/ReconcileIncompleteBookingCommandTest.php
git add app/Console/Commands/ReconcileIncompleteBookingCommand.php tests/Feature/Platforms/ReconcileIncompleteBookingCommandTest.php
git commit -m "feat(booking): add booking:reconcile-incomplete backfill command"
```

---

## Post-implementation verification

Not a task — do this before opening the PR.

- [ ] `php artisan test tests/Feature/Platforms/ tests/Feature/PublicSite/ tests/Unit/Platforms/` green.
- [ ] `vendor/bin/phpstan analyse` — note that this gate exits 0 on the dev branch even when it finds problems; read the output rather than trusting the exit code.
- [ ] `php artisan test --filter=AuditPipelineIntegrityTest` — no new directory was created under `app/Services/`, `app/Http/Controllers/Api/`, `app/Jobs/`, or `tests/Feature/`, so this should pass untouched. `app/Console/Commands/` is not a scoped group. Confirm rather than assume.
- [ ] Against dev, confirm the live account is fixed end to end:
      `curl -s https://dev-api.partna.au/api/public/profiles/simondoyle | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['pageOrder']); print([a for a in d['rankedActions'] if a['id']=='booking-services'])"`
      Expected after deploy + `booking:reconcile-incomplete --apply --invalidate`: `services` absent, `booking-services` present as `kind: external` with the Fresha URL.
- [ ] Hand the frontend the contract: `setup.complete`, `setup.reason`, `setup.seededFrom`, `setup.seededAt` on `GET /api/platforms/booking/status`; `GET /api/platforms/fresha/team` (now cached, `?refresh=1` to force); `POST /api/platforms/fresha/selection {employeeId}` unchanged.
