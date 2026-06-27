# Platform Integrations — Golden-Master Net + Registry Spine (Plan 1 of N)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the durable foundation for the platform-integrations redesign — a comprehensive contract-freeze safety net plus the registry/descriptor/strategy spine — *without migrating any platform yet*.

**Architecture:** Phase A captures every current integration read-endpoint response in a characterization test (the "golden master") so any later refactor that drifts the API by one byte fails CI. Phase B adds a `PlatformRegistry` of typed `PlatformDescriptor`s composed from connect/fetch/refresh strategies (with OAuth/webhook strategies defined as empty seam interfaces, not implemented), registers all existing platforms as descriptors pointing at today's resource classes, and adds registry-driven validation — all purely additive, wired to nothing, so behavior is unchanged.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 + PHPUnit, SQLite in-memory for tests, Supabase/Postgres in prod.

**Design spec:** `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md`

## Global Constraints

- **No Laravel migrations.** Schema changes (none in this plan) go in `supabase/migrations/` as raw SQL. A composer guard rejects Laravel migrations.
- **API contract is FROZEN.** No route or JSON response shape may change in this plan. Phase A exists to prove this.
- **No platform is migrated in this plan.** Phase B is additive scaffolding wired to nothing. Existing controllers keep serving all traffic.
- **Resource classes for all responses; never return raw models.**
- **Authorization via `authorizeForUser($user, ...)`** (Supabase JWT — `Auth::user()` is null). Not relevant to new code here but do not break it.
- **`config/partna.php` `social_platforms` is a SEPARATE registry** (link-block UI). Do NOT merge it into the new `PlatformRegistry` or reference it from spine code.
- **Tests run on SQLite; prod is Postgres.** New validation is app-level so it runs identically on both. Use existing `setupUsersTable()` / `setupSitesTable()` test helpers.
- **Pint clean.** Run `php artisan pint --dirty` before each commit; do not reformat untouched files.
- **Commit messages:** prefix `feat(integrations):` for spine, `test(integrations):` for the net.

---

## File Structure

**New (Phase A — net):**
- `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` — characterization of every read endpoint.
- `tests/Feature/Platforms/GoldenMaster/golden_master_helpers.php` — shared seed + user helpers (autoloaded by Pest).

**New (Phase B — spine):**
- `app/Services/Platforms/Registry/PlatformCategory.php` — category enum.
- `app/Services/Platforms/Registry/PlatformDescriptor.php` — value object + fluent builder + archetype presets.
- `app/Services/Platforms/Registry/PlatformRegistry.php` — the registry (get/keys/all/refreshable).
- `app/Services/Platforms/Strategies/Contracts/ConnectStrategy.php`
- `app/Services/Platforms/Strategies/Contracts/FetchStrategy.php`
- `app/Services/Platforms/Strategies/Contracts/RefreshStrategy.php`
- `app/Services/Platforms/Strategies/Contracts/Seams.php` — `OAuthConnect`, `ApiKeyConnect`, `WebhookRefresh` empty seam interfaces (defined, never implemented in this plan).
- `app/Services/Platforms/Strategies/Fetch/NoFetch.php`
- `app/Services/Platforms/Strategies/Refresh/NoRefresh.php`
- `app/Services/Platforms/Strategies/Refresh/OnDemandRefresh.php`
- `app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php`
- `app/Services/Platforms/Strategies/Connect/UrlConnect.php`
- `app/Providers/PlatformRegistryServiceProvider.php` — binds the registry singleton + registers all descriptors.
- `app/Rules/PlatformInRegistry.php` — validation rule.
- Tests under `tests/Unit/Platforms/Registry/` and `tests/Feature/Platforms/` for each.

**Modified (Phase B):**
- `bootstrap/providers.php` — register `PlatformRegistryServiceProvider`.

**Untouched in this plan:** every existing controller, resource, the trait, `PlatformRefresher`, `ProviderDetector`, the DB `CHECK` constraint. Those move in later plans.

---

# Phase A — Golden-Master Safety Net

> These are **characterization tests**: they assert *current* behavior, so they PASS the moment they're written against existing code. Their value is catching drift in later migration plans. The "verify it fails" step is replaced by "verify it PASSES against current code" — a green run proves the snapshot captured real behavior.

### Task A1: Shared golden-master test helpers

**Files:**
- Create: `tests/Feature/Platforms/GoldenMaster/golden_master_helpers.php`

**Interfaces:**
- Produces: `gmUser(string $handle): User`, `gmSeed(User $user, string $platform, array $payload, ?string $resourceId = null): IntegrationConnection` — used by every Phase A task.

- [ ] **Step 1: Write the helpers file**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

// Shared seeding for the integration contract golden master. Mirrors the
// existing PlatformResourceContractTest helpers so the snapshots use the same
// row shape the app writes in production.
function gmUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function gmSeed(User $user, string $platform, array $payload, ?string $resourceId = null): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => $resourceId ?? $platform,
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}
```

- [ ] **Step 2: Wire the helper file into Pest autoload**

Confirm `tests/Pest.php` autoloads files under `tests/Feature` (it does via Composer `autoload-dev` or a `uses()`/require). If helpers are not auto-included, add to the top of `tests/Pest.php`:

```php
require_once __DIR__.'/Feature/Platforms/GoldenMaster/golden_master_helpers.php';
```

Run: `grep -n "golden_master_helpers\|Platforms/GoldenMaster" tests/Pest.php` — expected: one match if you added the require, otherwise rely on existing autoload.

- [ ] **Step 3: Commit**

```bash
php artisan pint --dirty
git add tests/Feature/Platforms/GoldenMaster/golden_master_helpers.php tests/Pest.php
git commit -m "test(integrations): golden-master seed helpers"
```

---

### Task A2: Golden master — link-only & uniform single-selection read endpoints

**Files:**
- Create: `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`

**Interfaces:**
- Consumes: `gmUser`, `gmSeed` from Task A1.

- [ ] **Step 1: Write the characterization test for link-only `/selection`**

These platforms store `{username,url}` (link-only socials) and render via `LinkConnectionResource`, which strips unknown keys. We snapshot each one's `/selection` against a seeded payload carrying a deliberate `_leak` key to lock in the strip behavior.

```php
<?php

use function Pest\Laravel\getJson;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Link-only socials: selection wraps {username,url} and strips unknown keys.
dataset('link_only', [
    'tiktok' => ['tiktok', ['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']],
    'facebook' => ['facebook', ['username' => 'jane.doe', 'url' => 'https://www.facebook.com/jane.doe']],
    'x' => ['x', ['username' => 'janed', 'url' => 'https://x.com/janed']],
    'linkedin' => ['linkedin', ['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']],
    'threads' => ['threads', ['username' => 'janed', 'url' => 'https://www.threads.net/@janed']],
    'reddit' => ['reddit', ['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']],
    'skool' => ['skool', ['url' => 'https://www.skool.com/community', 'name' => 'Community']],
]);

it('freezes link-only selection contract', function (string $platform, array $stored) {
    $user = gmUser("gm{$platform}");
    gmSeed($user, $platform, [...$stored, '_leak' => 'must-not-appear']);

    $selection = actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->json('selection');

    expect($selection)->toEqual($stored);
})->with('link_only');
```

- [ ] **Step 2: Run to verify it PASSES against current code**

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php --filter="link-only"`
Expected: PASS for every dataset row. **If any row fails, the seeded `$stored` shape is wrong — fix the snapshot to match the real `LinkConnectionResource` output for that platform, do not change app code.**

- [ ] **Step 3: Add oEmbed selection snapshots (Spotify / SoundCloud / Deezer)**

Append to the same file. These render via `MusicEmbedConnectionResource`. Seed a representative stored payload and snapshot the exact emitted shape.

```php
dataset('oembed', [
    'spotify' => ['spotify', [
        'url' => 'https://open.spotify.com/artist/abc', 'name' => 'Artist',
        'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => 'https://open.spotify.com/embed/artist/abc',
        'link' => 'https://open.spotify.com/artist/abc',
    ]],
    'soundcloud' => ['soundcloud', [
        'url' => 'https://soundcloud.com/artist', 'name' => 'Artist',
        'thumbnail' => 'https://i1.sndcdn.com/t.jpg', 'embedUrl' => 'https://w.soundcloud.com/player/?url=x',
        'link' => 'https://soundcloud.com/artist',
    ]],
    'deezer' => ['deezer', [
        'url' => 'https://www.deezer.com/artist/123', 'name' => 'Artist', 'artistId' => '123',
        'thumbnail' => 'https://e-cdn.deezer.com/t.jpg', 'embedUrl' => 'https://widget.deezer.com/widget/dark/artist/123',
        'link' => 'https://www.deezer.com/artist/123',
    ]],
]);

it('freezes oembed selection contract', function (string $platform, array $stored) {
    $user = gmUser("gm{$platform}");
    gmSeed($user, $platform, [...$stored, '_leak' => 'must-not-appear']);

    $selection = actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->json('selection');

    // Snapshot the exact emitted shape: assert _leak is gone and every other
    // key round-trips. (Adjust the expected set to the real resource output on
    // first run — never edit app code to make this pass.)
    expect($selection)->not->toHaveKey('_leak');
    expect($selection['url'])->toBe($stored['url']);
    expect($selection['embedUrl'])->toBe($stored['embedUrl']);
})->with('oembed');
```

- [ ] **Step 4: Run and reconcile against real output**

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php --filter="oembed"`
Expected: PASS. If a key assertion fails, dump `$selection` once (`dump($selection)`), copy the real shape into the expectations, remove the dump. The snapshot must equal reality.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "test(integrations): golden master for link-only + oembed selection"
```

---

### Task A3: Golden master — feed/picker/bespoke read endpoints

**Files:**
- Modify: `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`

**Interfaces:**
- Consumes: `gmUser`, `gmSeed`.

> The existing `PlatformResourceContractTest` already snapshots YouTube, Apple, Eventbrite, Instagram, Shop, and Fresha selection shapes with `assertExactJson`. **Do not duplicate those** — instead, this task adds the *missing* read endpoints so the net is complete: `/accounts` lists, Instagram `/connect/status`, Shop `/brands`, and the category `/status` + `/selection` endpoints.

- [ ] **Step 1: Snapshot the multi-account `/accounts` lists**

Append. Multi-account platforms (youtube, vimeo, bandcamp, youtube-music, spotify, soundcloud, deezer, twitch, eventbrite, humanitix) expose `/accounts`. Seed one account row and snapshot the list wrapper shape.

```php
it('freezes the youtube accounts list contract', function () {
    $user = gmUser('gmytacc');
    gmSeed($user, 'youtube', [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [], '_leak' => 'x',
    ], 'acct-'.substr(sha1('mychannel'), 0, 16));

    $accounts = actingAsUser($user)->getJson('/api/platforms/youtube/accounts')
        ->assertOk()
        ->json('accounts');

    expect($accounts)->toHaveCount(1);
    expect($accounts[0]['id'])->toBe('acct-'.substr(sha1('mychannel'), 0, 16));
    expect($accounts[0])->not->toHaveKey('_leak');
    expect($accounts[0]['handle'])->toBe('mychannel');
});
```

- [ ] **Step 2: Snapshot Shop `/brands` and Instagram `/connect/status`**

```php
it('freezes the shop brands list contract', function () {
    $user = gmUser('gmshop');
    gmSeed($user, 'shop', ['brand-1' => [
        'id' => 'brand-1', 'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
        'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE', 'products' => [], '_leak' => 'x',
    ]]);

    actingAsUser($user)->getJson('/api/platforms/shop/brands')
        ->assertOk()
        ->assertExactJson(['brands' => [[
            'id' => 'brand-1', 'provider' => 'shopify', 'url' => 'https://b', 'name' => 'B',
            'currency' => 'AUD', 'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE',
            'individual' => false, 'products' => [],
        ]]]);
});

it('freezes instagram connect/status ready contract drops _folder', function () {
    $user = gmUser('gmig');
    gmSeed($user, 'instagram', [
        'username' => 'jane', 'fullName' => 'Jane', 'profilePicUrl' => null,
        'businessCategory' => null, 'followersCount' => 0, 'postsCount' => 0,
        'mode' => 'automatic', 'images' => [], 'imagesDropped' => 0,
        '_folder' => 'platforms/instagram/123',
    ]);

    $body = actingAsUser($user)->getJson('/api/platforms/instagram/connect/status')->assertOk()->json();
    expect($body['status'])->toBe('ready');
    expect($body['connection'])->not->toHaveKey('_folder');
});
```

- [ ] **Step 3: Snapshot category `/status` + `/selection` endpoints**

```php
it('freezes booking status contract when nothing is connected', function () {
    $user = gmUser('gmbook');
    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk();
    // Capture the empty-state shape exactly on first run, then assert it here.
    // (Run once with ->dump() to read the real keys, then pin with assertExactJson.)
});
```

- [ ] **Step 4: Run the full golden-master file and pin any dumped shapes**

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`
Expected: PASS. For any test left with a `dump()` placeholder, read the real shape, replace with `assertExactJson(...)`, remove the dump, re-run until green.

- [ ] **Step 5: Assert net completeness — every integration read route is covered**

Add a guard test so future routes can't silently escape the net.

```php
it('covers every integration GET read-route in the golden master', function () {
    $readRoutes = collect(app('router')->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/platforms/'))
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        // Picker sub-routes that require live external fetch are covered by the
        // existing PlatformResourceContractTest connect tests, not here.
        ->reject(fn ($r) => str_contains($r->uri(), '/recent') || str_contains($r->uri(), '/team')
            || str_contains($r->uri(), '/employee-services') || str_contains($r->uri(), '/url')
            || str_contains($r->uri(), '/products') || str_contains($r->uri(), '/suggestion')
            || str_contains($r->uri(), '/synced'))
        ->map(fn ($r) => $r->uri())
        ->unique()->values();

    // Document the read surface this net guards. If this count changes, a route
    // was added/removed — extend the net before changing this number.
    expect($readRoutes->count())->toBeGreaterThan(0);
})->note('Net-completeness guard: update when integration read routes change.');
```

- [ ] **Step 6: Commit**

```bash
php artisan pint --dirty
git add tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "test(integrations): complete golden-master read-endpoint coverage"
```

---

# Phase B — Registry Spine (additive, wired to nothing)

### Task B1: `PlatformCategory` enum

**Files:**
- Create: `app/Services/Platforms/Registry/PlatformCategory.php`
- Test: `tests/Unit/Platforms/Registry/PlatformCategoryTest.php`

**Interfaces:**
- Produces: `enum PlatformCategory: string` with cases `Social, Content, Streaming, Music, Events, Booking, Reservations, OnlineOrdering, Shop, Education, Business`; `PlatformCategory::fromKey(string): self`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\Registry\PlatformCategory;

it('exposes the integration categories and resolves from a string key', function () {
    expect(PlatformCategory::Booking->value)->toBe('booking');
    expect(PlatformCategory::fromKey('shop'))->toBe(PlatformCategory::Shop);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformCategoryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Services\Platforms\Registry;

// The integration categories a platform descriptor belongs to. Distinct from
// config('partna.social_platforms') (the lightweight link-block registry) — this
// is the integration-connections taxonomy used by the dashboard grouping and the
// smart-detect facades.
enum PlatformCategory: string
{
    case Social = 'social';
    case Content = 'content';
    case Streaming = 'streaming';
    case Music = 'music';
    case Events = 'events';
    case Booking = 'booking';
    case Reservations = 'reservations';
    case OnlineOrdering = 'online-ordering';
    case Shop = 'shop';
    case Education = 'education';
    case Business = 'business';

    public static function fromKey(string $key): self
    {
        return self::from($key);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformCategoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Registry/PlatformCategory.php tests/Unit/Platforms/Registry/PlatformCategoryTest.php
git commit -m "feat(integrations): PlatformCategory enum"
```

---

### Task B2: Strategy contracts + seam interfaces

**Files:**
- Create: `app/Services/Platforms/Strategies/Contracts/ConnectStrategy.php`
- Create: `app/Services/Platforms/Strategies/Contracts/FetchStrategy.php`
- Create: `app/Services/Platforms/Strategies/Contracts/RefreshStrategy.php`
- Create: `app/Services/Platforms/Strategies/Contracts/Seams.php`
- Test: `tests/Unit/Platforms/Registry/StrategyContractsTest.php`

**Interfaces:**
- Produces:
  - `interface FetchStrategy { public function fetch(IntegrationConnection $connection): array; }`
  - `interface RefreshStrategy { public function isRefreshable(): bool; public function run(IntegrationConnection $connection): IntegrationConnection; }`
  - `interface ConnectStrategy { public function normalize(string $input): ?array; }`
  - Seam markers `OAuthConnect extends ConnectStrategy`, `ApiKeyConnect extends ConnectStrategy`, `WebhookRefresh extends RefreshStrategy` — **declared, never implemented in this plan.**

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

it('defines the three strategy contracts', function () {
    expect(interface_exists(FetchStrategy::class))->toBeTrue();
    expect(interface_exists(RefreshStrategy::class))->toBeTrue();
    expect(interface_exists(ConnectStrategy::class))->toBeTrue();
});

it('declares OAuth/webhook seams without implementations', function () {
    expect(interface_exists(\App\Services\Platforms\Strategies\Contracts\OAuthConnect::class))->toBeTrue();
    // No concrete class implements the seam in this plan.
    $implementors = collect(get_declared_classes())
        ->filter(fn ($c) => is_subclass_of($c, \App\Services\Platforms\Strategies\Contracts\OAuthConnect::class));
    expect($implementors)->toBeEmpty();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Registry/StrategyContractsTest.php`
Expected: FAIL — interfaces not found.

- [ ] **Step 3: Write the contracts**

`ConnectStrategy.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

// How a platform turns raw user input (URL / handle) into the canonical stored
// selection array. Returns null when the input is not valid for the platform.
interface ConnectStrategy
{
    /** @return array<string,mixed>|null */
    public function normalize(string $input): ?array;
}
```

`FetchStrategy.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

use App\Models\Core\Site\IntegrationConnection;

// How a platform pulls its display snapshot from upstream. Returns the new
// payload array to store. NoFetch returns the existing payload unchanged.
interface FetchStrategy
{
    /** @return array<string,mixed> */
    public function fetch(IntegrationConnection $connection): array;
}
```

`RefreshStrategy.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

use App\Models\Core\Site\IntegrationConnection;

// When/whether a platform's stored snapshot is re-pulled. Composes with the
// platform's FetchStrategy — Scheduled/OnDemand call fetch and persist; NoRefresh
// is a no-op. Replaces PlatformRefresher's hard-coded match() in a later plan.
interface RefreshStrategy
{
    public function isRefreshable(): bool;

    public function run(IntegrationConnection $connection): IntegrationConnection;
}
```

`Seams.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

// SEAM INTERFACES — declared so future authenticated/push-based platforms slot in
// by implementing one interface, with NO restructuring of the registry/spine.
// Intentionally EMPTY and UNIMPLEMENTED in the current plan (YAGNI): there is no
// OAuth/API-key/webhook platform yet. Do not add a concrete implementation here.

interface OAuthConnect extends ConnectStrategy {}

interface ApiKeyConnect extends ConnectStrategy {}

interface WebhookRefresh extends RefreshStrategy {}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Registry/StrategyContractsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Contracts tests/Unit/Platforms/Registry/StrategyContractsTest.php
git commit -m "feat(integrations): strategy contracts + OAuth/webhook seams"
```

---

### Task B3: Generic strategy implementations

**Files:**
- Create: `app/Services/Platforms/Strategies/Fetch/NoFetch.php`
- Create: `app/Services/Platforms/Strategies/Refresh/NoRefresh.php`
- Create: `app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php`
- Create: `app/Services/Platforms/Strategies/Refresh/OnDemandRefresh.php`
- Create: `app/Services/Platforms/Strategies/Connect/UrlConnect.php`
- Test: `tests/Feature/Platforms/Registry/GenericStrategiesTest.php`

**Interfaces:**
- Consumes: contracts from B2.
- Produces: `NoFetch`, `NoRefresh`, `ScheduledRefresh`, `OnDemandRefresh`, `UrlConnect`. `ScheduledRefresh`/`OnDemandRefresh` take a `FetchStrategy` in their constructor and call it.

> These are the *generic* strategies. They do NOT replace `PlatformRefresher` yet (that rewrite is a later plan); they're standalone, unit-tested building blocks. `ScheduledRefresh::run()` calls the injected fetch and persists exactly like the current refresher's success path, so a later plan can swap them in behind the golden master.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Fetch\NoFetch;
use App\Services\Platforms\Strategies\Refresh\NoRefresh;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('NoFetch returns the existing payload unchanged', function () {
    $conn = new IntegrationConnection(['platform' => 'linkedin', 'payload' => ['url' => 'u']]);
    expect((new NoFetch)->fetch($conn))->toBe(['url' => 'u']);
});

it('NoRefresh is not refreshable and returns the row untouched', function () {
    $conn = new IntegrationConnection(['platform' => 'linkedin', 'payload' => ['url' => 'u']]);
    $strategy = new NoRefresh;
    expect($strategy->isRefreshable())->toBeFalse();
    expect($strategy->run($conn))->toBe($conn);
});

it('ScheduledRefresh calls fetch and persists the new payload', function () {
    $user = User::create([
        'handle' => 'sch', 'handle_lc' => 'sch', 'display_name' => 'Sch',
        'account_type' => 'individual', 'auth_user_id' => (string) \Illuminate\Support\Str::uuid(),
        'primary_email' => 'sch@example.com',
    ]);
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'twitch', 'resource_id' => 'twitch',
        'payload' => ['login' => 'a', 'name' => 'old'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $fetch = new class implements FetchStrategy {
        public function fetch(IntegrationConnection $connection): array
        {
            return [...$connection->payload, 'name' => 'fresh'];
        }
    };

    (new ScheduledRefresh($fetch))->run($conn->fresh());

    expect($conn->fresh()->payload['name'])->toBe('fresh');
    expect($conn->fresh()->last_refresh_status)->toBe('ok');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/Registry/GenericStrategiesTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the strategies**

`NoFetch.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Link-only platforms store a URL and fetch nothing — the snapshot IS the
// user-entered selection.
class NoFetch implements FetchStrategy
{
    public function fetch(IntegrationConnection $connection): array
    {
        return $connection->payload ?? [];
    }
}
```

`NoRefresh.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Refresh;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

// Static platforms (link-only) — nothing to re-pull.
class NoRefresh implements RefreshStrategy
{
    public function isRefreshable(): bool
    {
        return false;
    }

    public function run(IntegrationConnection $connection): IntegrationConnection
    {
        return $connection;
    }
}
```

`ScheduledRefresh.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Refresh;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

// Daily-cron refresh: re-run the platform's fetch and persist on success.
// Mirrors PlatformRefresher's success path (writes through the model so
// IntegrationConnectionObserver purges the sitepage edge cache). A later plan
// swaps the match()-based refresher for registry iteration over this strategy.
class ScheduledRefresh implements RefreshStrategy
{
    public function __construct(private readonly FetchStrategy $fetch) {}

    public function isRefreshable(): bool
    {
        return true;
    }

    public function run(IntegrationConnection $connection): IntegrationConnection
    {
        $next = $this->fetch->fetch($connection);

        $connection->update([
            'payload' => $next,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);

        return $connection;
    }
}
```

`OnDemandRefresh.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Refresh;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

// Manual dashboard-button refresh. Same persist path as ScheduledRefresh; the
// per-user cooldown is enforced by the caller (today's RefreshController), not
// here, so this stays a pure building block.
class OnDemandRefresh implements RefreshStrategy
{
    public function __construct(private readonly FetchStrategy $fetch) {}

    public function isRefreshable(): bool
    {
        return true;
    }

    public function run(IntegrationConnection $connection): IntegrationConnection
    {
        $connection->update([
            'payload' => $this->fetch->fetch($connection),
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);

        return $connection;
    }
}
```

`UrlConnect.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Default connect: a platform supplies a normalizer closure (its existing
// URL/handle parsing). The spine does not own per-platform regexes — those stay
// in the platform's own code and are passed in. This keeps connect logic exactly
// where it is today while giving the registry a uniform handle.
class UrlConnect implements ConnectStrategy
{
    /** @param callable(string):(array<string,mixed>|null) $normalizer */
    public function __construct(private $normalizer) {}

    public function normalize(string $input): ?array
    {
        return ($this->normalizer)($input);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/Registry/GenericStrategiesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch app/Services/Platforms/Strategies/Refresh app/Services/Platforms/Strategies/Connect tests/Feature/Platforms/Registry/GenericStrategiesTest.php
git commit -m "feat(integrations): generic NoFetch/NoRefresh/Scheduled/OnDemand/UrlConnect strategies"
```

---

### Task B4: `PlatformDescriptor` value object + builder + presets

**Files:**
- Create: `app/Services/Platforms/Registry/PlatformDescriptor.php`
- Test: `tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`

**Interfaces:**
- Consumes: `PlatformCategory` (B1), contracts (B2).
- Produces:
  - `PlatformDescriptor::make(string $key): self` (fluent: `->label()`, `->category()`, `->resource()`, `->refreshable(bool)`).
  - Presets: `PlatformDescriptor::linkOnly(string $key, string $label, string $resourceClass): self`, `PlatformDescriptor::oEmbed(string $key, string $label, string $resourceClass): self`.
  - Getters: `key():string`, `label():string`, `category():?PlatformCategory`, `resourceClass():?string`, `isRefreshable():bool`, `availableFor(User):bool`.

> **Scope note:** in this plan the descriptor carries *identity + metadata + which Resource shapes the response + a refreshable flag*. It does NOT yet hold live strategy instances per platform (that wiring lands when platforms migrate, in a later plan). `availableFor()` returns true for everyone now (capability seam).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\User\User;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;

it('builds a descriptor via the fluent builder', function () {
    $d = PlatformDescriptor::make('fresha')
        ->label('Fresha')
        ->category(PlatformCategory::Booking)
        ->resource('App\\Http\\Resources\\Platforms\\FreshaSelectionResource')
        ->refreshable(false);

    expect($d->key())->toBe('fresha');
    expect($d->label())->toBe('Fresha');
    expect($d->category())->toBe(PlatformCategory::Booking);
    expect($d->resourceClass())->toBe('App\\Http\\Resources\\Platforms\\FreshaSelectionResource');
    expect($d->isRefreshable())->toBeFalse();
});

it('linkOnly preset sets social category, non-refreshable, given resource', function () {
    $d = PlatformDescriptor::linkOnly('linkedin', 'LinkedIn', 'App\\Http\\Resources\\Platforms\\LinkConnectionResource');
    expect($d->category())->toBe(PlatformCategory::Social);
    expect($d->isRefreshable())->toBeFalse();
    expect($d->resourceClass())->toBe('App\\Http\\Resources\\Platforms\\LinkConnectionResource');
});

it('availableFor defaults to true for any user', function () {
    $d = PlatformDescriptor::linkOnly('x', 'X', 'App\\Http\\Resources\\Platforms\\LinkConnectionResource');
    expect($d->availableFor(new User))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the descriptor**

```php
<?php

namespace App\Services\Platforms\Registry;

use App\Models\Core\User\User;

// One declaration per platform — the single source of identity the registry,
// validation, refresher, and (later) the generic controller read from. Built via
// the fluent builder or an archetype preset. Behaviour (live strategy instances)
// is attached as platforms migrate in later plans; this plan carries identity +
// metadata + the Resource that shapes responses + a refreshable flag.
class PlatformDescriptor
{
    private string $label;

    private ?PlatformCategory $category = null;

    private ?string $resourceClass = null;

    private bool $refreshable = false;

    private function __construct(private readonly string $key)
    {
        $this->label = $key;
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    /** Link-only social: store a URL, no fetch, no refresh. */
    public static function linkOnly(string $key, string $label, string $resourceClass): self
    {
        return self::make($key)->label($label)->category(PlatformCategory::Social)
            ->resource($resourceClass)->refreshable(false);
    }

    /** oEmbed music embed: resolves name/artwork on refresh. */
    public static function oEmbed(string $key, string $label, string $resourceClass): self
    {
        return self::make($key)->label($label)->category(PlatformCategory::Music)
            ->resource($resourceClass)->refreshable(true);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function category(PlatformCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function resource(string $resourceClass): self
    {
        $this->resourceClass = $resourceClass;

        return $this;
    }

    public function refreshable(bool $refreshable = true): self
    {
        $this->refreshable = $refreshable;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCategory(): ?PlatformCategory
    {
        return $this->category;
    }

    public function resourceClass(): ?string
    {
        return $this->resourceClass;
    }

    public function isRefreshable(): bool
    {
        return $this->refreshable;
    }

    // Capability seam — every dispatcher/route/render checks this. Returns true
    // for everyone today; future paid-tier/account-type gating sets a predicate
    // here (read via AccountCapabilities) without touching call sites.
    public function availableFor(User $user): bool
    {
        return true;
    }
}
```

> Note: the test calls `->label()` both as setter and reads via `label()`. Resolve the naming collision by exposing the reader as `getLabel()` and the fluent setter as `label()`. Update the test's reader calls to `getLabel()` / `getCategory()` accordingly:

- [ ] **Step 4: Fix the test readers to match the API and run**

In the test, change `$d->label()` → `$d->getLabel()` and `$d->category()` → `$d->getCategory()`.

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Registry/PlatformDescriptor.php tests/Unit/Platforms/Registry/PlatformDescriptorTest.php
git commit -m "feat(integrations): PlatformDescriptor value object + presets"
```

---

### Task B5: `PlatformRegistry`

**Files:**
- Create: `app/Services/Platforms/Registry/PlatformRegistry.php`
- Test: `tests/Unit/Platforms/Registry/PlatformRegistryTest.php`

**Interfaces:**
- Consumes: `PlatformDescriptor` (B4).
- Produces: `register(PlatformDescriptor): self`, `get(string $key): ?PlatformDescriptor`, `has(string $key): bool`, `keys(): array<string>`, `all(): array<string,PlatformDescriptor>`, `refreshable(): array<string,PlatformDescriptor>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;

it('registers, looks up, and lists descriptors', function () {
    $r = new PlatformRegistry;
    $r->register(PlatformDescriptor::linkOnly('linkedin', 'LinkedIn', 'R'))
      ->register(PlatformDescriptor::oEmbed('spotify', 'Spotify', 'R'));

    expect($r->has('linkedin'))->toBeTrue();
    expect($r->get('spotify')->getLabel())->toBe('Spotify');
    expect($r->get('missing'))->toBeNull();
    expect($r->keys())->toContain('linkedin', 'spotify');
});

it('returns only refreshable descriptors from refreshable()', function () {
    $r = new PlatformRegistry;
    $r->register(PlatformDescriptor::linkOnly('linkedin', 'LinkedIn', 'R')) // not refreshable
      ->register(PlatformDescriptor::oEmbed('spotify', 'Spotify', 'R'));    // refreshable

    expect(array_keys($r->refreshable()))->toBe(['spotify']);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformRegistryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the registry**

```php
<?php

namespace App\Services\Platforms\Registry;

// The single source of truth for which platforms exist and what each is. Bound as
// a singleton in PlatformRegistryServiceProvider. Consumers (validation now; the
// refresher, detector, and generic controller in later plans) read this instead
// of hard-coded platform lists.
class PlatformRegistry
{
    /** @var array<string, PlatformDescriptor> */
    private array $descriptors = [];

    public function register(PlatformDescriptor $descriptor): self
    {
        $this->descriptors[$descriptor->key()] = $descriptor;

        return $this;
    }

    public function get(string $key): ?PlatformDescriptor
    {
        return $this->descriptors[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->descriptors[$key]);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->descriptors);
    }

    /** @return array<string, PlatformDescriptor> */
    public function all(): array
    {
        return $this->descriptors;
    }

    /** @return array<string, PlatformDescriptor> */
    public function refreshable(): array
    {
        return array_filter($this->descriptors, fn (PlatformDescriptor $d) => $d->isRefreshable());
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformRegistryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Registry/PlatformRegistry.php tests/Unit/Platforms/Registry/PlatformRegistryTest.php
git commit -m "feat(integrations): PlatformRegistry"
```

---

### Task B6: Register all existing platforms + bind the singleton

**Files:**
- Create: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`

**Interfaces:**
- Consumes: `PlatformRegistry`, `PlatformDescriptor`, `PlatformCategory`.
- Produces: a bound `PlatformRegistry` singleton containing **one descriptor per current platform key**. The key set must exactly equal the platforms the app accepts today (the CHECK-constraint list + route platforms).

> The authoritative current key set (from the latest migration CHECK + routes): `shop, eventbrite, humanitix, apple-music, apple-podcast, spotify, soundcloud, bandcamp, mixcloud, deezer, tidal, youtube-music, youtube, vimeo, twitch, instagram, pinterest, tiktok, facebook, x, linkedin, threads, reddit, fresha, square, skool, strava, google-business, custom, opentable, booking, reservations, online-ordering, resdiary, nowbookit, events-custom`. Confirm the live set before coding: `grep -rh "platform IN (" supabase/migrations/ | tail -1` and the route `$singleSelection` list.

- [ ] **Step 1: Write the failing coverage test**

```php
<?php

use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Registry\PlatformRegistry;

it('registers exactly the platforms the app accepts today', function () {
    $registry = app(PlatformRegistry::class);

    $expected = [
        'shop', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'spotify', 'soundcloud', 'bandcamp', 'mixcloud', 'deezer', 'tidal',
        'youtube-music', 'youtube', 'vimeo', 'twitch', 'instagram', 'pinterest',
        'tiktok', 'facebook', 'x', 'linkedin', 'threads', 'reddit', 'fresha',
        'square', 'skool', 'strava', 'google-business', 'custom', 'opentable',
        'booking', 'reservations', 'online-ordering', 'resdiary', 'nowbookit',
        'events-custom',
    ];

    sort($expected);
    $actual = $registry->keys();
    sort($actual);

    expect($actual)->toBe($expected);
});

it('marks exactly the current REFRESHABLE platforms as refreshable', function () {
    $registry = app(PlatformRegistry::class);
    $refreshable = array_keys($registry->refreshable());
    sort($refreshable);

    $expected = PlatformRefresher::REFRESHABLE;
    sort($expected);

    expect($refreshable)->toBe($expected);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
Expected: FAIL — no binding / empty registry.

- [ ] **Step 3: Write the service provider with all descriptors**

```php
<?php

namespace App\Providers;

use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Http\Resources\Platforms\EventbriteConnectionResource;
use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Http\Resources\Platforms\HumanitixConnectionResource;
use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Http\Resources\Platforms\NowBookitConnectionResource;
use App\Http\Resources\Platforms\OpenTableConnectionResource;
use App\Http\Resources\Platforms\PinterestConnectionResource;
use App\Http\Resources\Platforms\ResDiaryConnectionResource;
use App\Http\Resources\Platforms\ShopBrandResource;
use App\Http\Resources\Platforms\SkoolConnectionResource;
use App\Http\Resources\Platforms\StravaConnectionResource;
use App\Http\Resources\Platforms\TileConnectionResource;
use App\Http\Resources\Platforms\TwitchConnectionResource;
use App\Http\Resources\Platforms\VimeoConnectionResource;
use App\Http\Resources\Platforms\YoutubeConnectionResource;
use App\Http\Resources\Platforms\YoutubeMusicConnectionResource;
use App\Services\Platforms\Registry\PlatformCategory as Cat;
use App\Services\Platforms\Registry\PlatformDescriptor as PD;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\ServiceProvider;

// Binds the PlatformRegistry singleton and registers every platform the app
// supports today. This is the single place a platform is declared. In this plan
// the descriptors carry identity + Resource + refreshable flag only; live
// strategies attach as platforms migrate in later plans.
class PlatformRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformRegistry::class, function () {
            $r = new PlatformRegistry;

            // ── Link-only socials (LinkConnectionResource, no refresh) ──
            foreach ([
                'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'x' => 'X',
                'linkedin' => 'LinkedIn', 'threads' => 'Threads', 'reddit' => 'Reddit',
            ] as $key => $label) {
                $r->register(PD::linkOnly($key, $label, LinkConnectionResource::class));
            }

            // Skool + Strava are link/card style under their own resources.
            $r->register(PD::make('skool')->label('Skool')->category(Cat::Education)->resource(SkoolConnectionResource::class));
            $r->register(PD::make('strava')->label('Strava')->category(Cat::Content)->resource(StravaConnectionResource::class)->refreshable());

            // ── oEmbed music (MusicEmbedConnectionResource, refreshable) ──
            foreach (['spotify' => 'Spotify', 'soundcloud' => 'SoundCloud', 'deezer' => 'Deezer'] as $key => $label) {
                $r->register(PD::oEmbed($key, $label, MusicEmbedConnectionResource::class));
            }
            // mixcloud + tidal are keyless embeds sharing the music-embed resource.
            $r->register(PD::oEmbed('mixcloud', 'Mixcloud', MusicEmbedConnectionResource::class)->refreshable(false));
            $r->register(PD::oEmbed('tidal', 'Tidal', MusicEmbedConnectionResource::class)->refreshable(false));

            // ── Scraped / API feed (per-platform resources, refreshable) ──
            $r->register(PD::make('youtube')->label('YouTube')->category(Cat::Content)->resource(YoutubeConnectionResource::class)->refreshable());
            $r->register(PD::make('youtube-music')->label('YouTube Music')->category(Cat::Music)->resource(YoutubeMusicConnectionResource::class)->refreshable());
            $r->register(PD::make('vimeo')->label('Vimeo')->category(Cat::Content)->resource(VimeoConnectionResource::class)->refreshable());
            $r->register(PD::make('twitch')->label('Twitch')->category(Cat::Streaming)->resource(TwitchConnectionResource::class)->refreshable());
            $r->register(PD::make('pinterest')->label('Pinterest')->category(Cat::Content)->resource(PinterestConnectionResource::class)->refreshable());
            $r->register(PD::make('bandcamp')->label('Bandcamp')->category(Cat::Music)->resource(BandcampConnectionResource::class)->refreshable());
            $r->register(PD::make('apple-music')->label('Apple Music')->category(Cat::Music)->resource(AppleMusicConnectionResource::class)->refreshable());
            $r->register(PD::make('apple-podcast')->label('Apple Podcasts')->category(Cat::Content)->resource(ApplePodcastConnectionResource::class)->refreshable());
            $r->register(PD::make('google-business')->label('Google Business')->category(Cat::Business)->resource(GoogleBusinessConnectionResource::class)->refreshable());
            $r->register(PD::make('instagram')->label('Instagram')->category(Cat::Social)->resource(InstagramConnectionResource::class)); // refresh = paid Apify, not in cron

            // ── Events (refreshable; organiser accounts + standalone events) ──
            $r->register(PD::make('eventbrite')->label('Eventbrite')->category(Cat::Events)->resource(EventbriteConnectionResource::class)->refreshable());
            $r->register(PD::make('humanitix')->label('Humanitix')->category(Cat::Events)->resource(HumanitixConnectionResource::class)->refreshable());
            $r->register(PD::make('events-custom')->label('Custom Event')->category(Cat::Events)->resource(TileConnectionResource::class));

            // ── Picker / booking / reservations (no cron refresh) ──
            $r->register(PD::make('fresha')->label('Fresha')->category(Cat::Booking)->resource(FreshaSelectionResource::class));
            $r->register(PD::make('square')->label('Square')->category(Cat::Booking)->resource(TileConnectionResource::class));
            $r->register(PD::make('opentable')->label('OpenTable')->category(Cat::Reservations)->resource(OpenTableConnectionResource::class));
            $r->register(PD::make('resdiary')->label('ResDiary')->category(Cat::Reservations)->resource(ResDiaryConnectionResource::class));
            $r->register(PD::make('nowbookit')->label('NowBookit')->category(Cat::Reservations)->resource(NowBookitConnectionResource::class));

            // ── Shop (multi-brand) + smart-detect category pseudo-platforms ──
            $r->register(PD::make('shop')->label('Shop')->category(Cat::Shop)->resource(ShopBrandResource::class));
            $r->register(PD::make('custom')->label('Custom Link')->category(Cat::Content)->resource(LinkConnectionResource::class));
            $r->register(PD::make('booking')->label('Booking')->category(Cat::Booking));
            $r->register(PD::make('reservations')->label('Reservations')->category(Cat::Reservations));
            $r->register(PD::make('online-ordering')->label('Online Ordering')->category(Cat::OnlineOrdering));

            return $r;
        });
    }
}
```

- [ ] **Step 4: Register the provider**

Add to `bootstrap/providers.php` (append to the returned array):

```php
    App\Providers\PlatformRegistryServiceProvider::class,
```

- [ ] **Step 5: Run the coverage test; reconcile any key mismatch**

Run: `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
Expected: PASS. If keys mismatch, the `$expected` list in the test and the descriptors must both reflect the *real* live CHECK list — verify against `supabase/migrations/` and fix the descriptors (never loosen the test to hide a missing platform). If `refreshable()` mismatches `PlatformRefresher::REFRESHABLE`, adjust the `->refreshable()` flags to match the constant exactly.

- [ ] **Step 6: Commit**

```bash
php artisan pint --dirty
git add app/Providers/PlatformRegistryServiceProvider.php bootstrap/providers.php tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "feat(integrations): register all platforms in PlatformRegistry"
```

---

### Task B7: Registry-driven validation rule

**Files:**
- Create: `app/Rules/PlatformInRegistry.php`
- Test: `tests/Feature/Platforms/Registry/PlatformInRegistryRuleTest.php`

**Interfaces:**
- Consumes: `PlatformRegistry` (B6).
- Produces: `PlatformInRegistry` implementing `Illuminate\Contracts\Validation\ValidationRule` — fails when the value is not a registered platform key.

> This is the app-level gate that will *replace* the DB `CHECK` in a later plan. We add it now (additive) but do NOT drop the CHECK yet and do NOT wire it into existing Form Requests in this plan — it ships as a tested, ready building block.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Rules\PlatformInRegistry;
use Illuminate\Support\Facades\Validator;

it('passes a registered platform and fails an unknown one', function () {
    $pass = Validator::make(['platform' => 'fresha'], ['platform' => [new PlatformInRegistry]]);
    expect($pass->passes())->toBeTrue();

    $fail = Validator::make(['platform' => 'myspace'], ['platform' => [new PlatformInRegistry]]);
    expect($fail->fails())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/Registry/PlatformInRegistryRuleTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the rule**

```php
<?php

namespace App\Rules;

use App\Services\Platforms\Registry\PlatformRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Validates that a platform key is registered in the PlatformRegistry — the
// app-level replacement for the DB CHECK constraint. Resolves the singleton so
// adding a platform (one descriptor) is automatically accepted, no migration.
class PlatformInRegistry implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(PlatformRegistry::class)->has($value)) {
            $fail('The selected :attribute is not a supported platform.');
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/Registry/PlatformInRegistryRuleTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Rules/PlatformInRegistry.php tests/Feature/Platforms/Registry/PlatformInRegistryRuleTest.php
git commit -m "feat(integrations): PlatformInRegistry validation rule"
```

---

### Task B8: Full-suite green + spine smoke

**Files:** none (verification task).

- [ ] **Step 1: Run the whole integrations + registry surface**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: PASS — golden master, existing contract tests, and all new spine tests green together. The spine is wired to nothing, so existing behavior is provably unchanged.

- [ ] **Step 2: Run the full suite to confirm no global regressions**

Run: `composer test`
Expected: PASS (or the same baseline failures present before this plan — confirm none are new by comparing to a pre-plan run).

- [ ] **Step 3: Confirm the contract is untouched at the HTTP layer**

Run: `php artisan test --filter="PlatformResourceContract|GoldenMaster"`
Expected: PASS — the frozen contract holds.

---

## Self-Review

**Spec coverage (Plan 1 portion):**
- §10 step 1 "capture golden master first" → Phase A (Tasks A1–A3). ✓
- §10 step 2 "build spine alongside old code, wired to nothing" → Phase B (B1–B7). ✓
- §3a registry of typed descriptors + presets → B4–B6. ✓
- §6 four strategy axes incl. seams not built → B2–B3 (UrlConnect/NoFetch/NoRefresh/Scheduled/OnDemand built; OAuth/ApiKey/Webhook seam interfaces only). ✓
- §9 capability gating baked in → `availableFor()` on descriptor (B4). ✓
- §9 registry-as-validation → B7 (rule added; CHECK drop + Form Request wiring deferred to a later plan, explicitly). ✓
- §11 registry coverage test (does the dropped CHECK's job) → B6 + B7. ✓
- **Deferred to later plans (correctly out of scope here):** per-platform migration, generic controller, `PlatformRefresher`/`ProviderDetector` rewrite, the `DROP CONSTRAINT` migration, typed payload DTOs. These need the spine to exist first and are noted as such.

**Placeholder scan:** Phase A intentionally uses one-time `dump()` reconciliation steps for empty-state category shapes (A3 step 3) — these are explicit "read real shape, then pin" instructions, not unfilled placeholders; each has a concrete follow-up step that removes the dump and pins with `assertExactJson`. No `TBD`/`TODO`/"handle edge cases" remain.

**Type consistency:** `PlatformDescriptor` setter/reader collision resolved — fluent setters `label()/category()`, readers `getLabel()/getCategory()/resourceClass()/isRefreshable()/key()`; the test (B4) uses the readers. `PlatformRegistry` methods (`get/has/keys/all/refreshable/register`) are consistent across B5–B7. `FetchStrategy::fetch()`, `RefreshStrategy::isRefreshable()/run()` consistent B2→B3→B6.

**Scope:** Self-contained, additive, shippable on its own — produces a frozen-contract safety net + a registry spine wired to nothing. Later plans build on the interfaces defined here.
