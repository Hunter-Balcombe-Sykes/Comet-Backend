# Platform Integrations — oEmbed Archetype (Plan 3a of N)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the oEmbed music archetype (spotify, soundcloud, deezer — plus the dormant mixcloud/tidal) onto the registry spine: add an `EmbedPayload` typed DTO, generalize `GenericPlatformController` to serve the multi-account selection/accounts read paths through a descriptor-resolved payload class, and build the `OEmbedFetch` + `DeezerFetch` strategies (parity-tested against the current `PlatformRefresher`) so the Plan-6 refresher swap is provably behaviour-preserving — with the API contract frozen byte-for-byte throughout.

**Architecture:** This is the first half of the original "Plan 3 (embed + feed)", split out because the combined plan exceeded ~12 tasks (see "Why this is split" below). Plan 2 left `GenericPlatformController` serving the six link-only platforms with a hard-coded `LinkPayload`. This plan (a) adds a second payload DTO (`EmbedPayload`), (b) teaches the descriptor to carry a `payloadClass` and a `FetchStrategy`, (c) generalizes the generic controller's READ methods (selection/accounts/removeAccount/forget) to resolve the payload class from the descriptor — so the same controller serves link AND embed read paths — and (d) extracts the refresher's `musicEmbedPayload`/`deezerPayload` shaping into `FetchStrategy` implementations. The thin embed controllers (`SpotifyController`/`SoundcloudController`/`DeezerController`) **keep their `connect()`** (it fetches on connect); only their read routes re-point to the generic controller. No controller is deleted this plan.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 + PHPUnit, SQLite in-memory for tests, Supabase/Postgres in prod.

**Design spec:** `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md` (§6 ② FetchStrategy + ③ RefreshStrategy, §7 oEmbed archetype row, §8 typed payloads, §10 strangler migration, §11 testing, §15 edge-platform open questions).

**Builds on (MERGED):**
- `docs/superpowers/plans/2026-06-27-platform-integrations-registry-spine.md` (Plan 1 — registry/descriptor/strategy interfaces).
- `docs/superpowers/plans/2026-06-27-platform-integrations-link-only.md` (Plan 2 — `GenericPlatformController`, `LinkPayload`, descriptor `connect()` strategy, the golden-master net). The classes this plan extends already exist on `development`: `app/Http/Controllers/Api/Platforms/GenericPlatformController.php`, `app/Services/Platforms/Payloads/LinkPayload.php`, `app/Services/Platforms/Registry/{PlatformRegistry,PlatformDescriptor,PlatformCategory}.php`, `app/Services/Platforms/Strategies/Contracts/{FetchStrategy,RefreshStrategy,ConnectStrategy}.php`, `app/Services/Platforms/Strategies/Fetch/NoFetch.php`, `app/Services/Platforms/Strategies/Refresh/{NoRefresh,OnDemandRefresh,ScheduledRefresh}.php`, `app/Providers/PlatformRegistryServiceProvider.php`, and the golden-master net under `tests/Feature/Platforms/GoldenMaster/`.

## Why this is split (3a embed / 3b feed)

The original Plan 3 covered both the oEmbed archetype (3 platforms, 1 shared resource) and the scraped/API-feed archetype (9 platforms, 9 divergent resources, 9 fetch shapes). Together that is ~20 tasks. Per the writing-plans scope check, it is broken into:
- **Plan 3a (this file)** — oEmbed: builds the shared spine extensions (descriptor `payload()`/`fetch()`, generic-controller read generalization, the fetch-failure exception taxonomy) plus the 3 embed platforms.
- **Plan 3b** — `docs/superpowers/plans/2026-06-28-platform-integrations-feed.md` — feed: `FeedPayload` + the 9 feed platforms. **3b depends on 3a being merged** (it reuses the descriptor `payload()`/`fetch()` methods, the generalized generic controller, and the `FetchShapeException`/`FetchUnavailableException` taxonomy this plan introduces).

## Global Constraints

- **No Laravel migrations.** This plan touches NO schema (the `DROP CONSTRAINT` is Plan 6). A composer guard (`guard:no-laravel-migrations`) rejects Laravel migrations regardless.
- **API contract is FROZEN — byte-for-byte.** Every route URI, every JSON response shape, every error-message string stays identical. `IntegrationContractGoldenMasterTest`, `PlatformResourceContractTest`, `IntegrationsV3ConnectionTest`, and `ScraperPlatformsConnectionTest` must stay green after every task. No assertion in those files may be loosened to make a change pass.
- **The net-completeness count stays `52`.** `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` (~line 184) asserts `expect($readRoutes->count())->toBe(52)`. Every route change here re-points an existing `api/platforms/` GET URI to a different controller WITHOUT changing the URI, so this number does NOT change. If it ever does, a route was added/removed — stop and reconcile before touching the number.
- **Behaviour-preserving fetch strategies (the proof obligation).** Each `FetchStrategy` is adapted from a `PlatformRefresher` private method and MUST produce the byte-identical success payload, asserted by a parity test that runs BOTH the real `PlatformRefresher::refresh()` and the new strategy against the same mocked upstream and compares the resulting payload (spec §11 "fetch is mockable"). The strategy is NOT yet wired into the refresher — that swap is Plan 6; this plan only builds + attaches + proves.
- **`PlatformRefresher` is READ-ONLY this plan.** Do not edit it. The fetch strategies mirror its private methods; the refresher's `match()` rewrite is Plan 6.
- **Resource classes for all responses; never return raw models.** The generic controller serializes through the descriptor's `resourceClass()` (spotify/soundcloud/deezer/mixcloud/tidal → `MusicEmbedConnectionResource`).
- **Authorization via the trait chokepoint.** `ManagesIntegrationConnection` already runs `authorizeForUser($user, ...)` on every read/write/delete. The generic controller reuses the trait unchanged — never add inline 403 aborts (CLAUDE.md policy rule + CI guard).
- **Capability gate stays only on the create path.** `connect()` (create) calls `$descriptor->availableFor($user)` per spec §9 (true for everyone today). Do NOT gate selection/accounts/forget (reads/removals of already-owned data). `connect()` for embed stays on the thin controllers this plan — they do not currently call `availableFor()` and we do NOT add it (out of scope; their behaviour is frozen).
- **Tests run on SQLite; prod is Postgres.** All new code is app-level and engine-agnostic. Use the existing `setupUsersTable()` / `setupSitesTable()` / `actingAsUser()` helpers, and the golden-master `gmUser()` / `gmSeed()` helpers (`tests/Feature/Platforms/GoldenMaster/golden_master_helpers.php`).
- **Pint clean.** Run `php artisan pint --dirty` before every commit; never reformat untouched files (CLAUDE.md: surgical commits; the Pint baseline is clean as of c7c150d2).
- **Commit prefixes:** `feat(integrations):` for new toolkit classes (DTO, strategies, descriptor methods), `refactor(integrations):` for a platform read-path migration (route re-point + descriptor attach), `test(integrations):` for test-only additions.

---

## Prerequisite check (run before Task 1)

- [ ] **Confirm Plan 2 is merged and the spine + toolkit exist**

Run:
```bash
git fetch && git pull && git log --oneline -10
ls app/Http/Controllers/Api/Platforms/GenericPlatformController.php \
   app/Services/Platforms/Payloads/LinkPayload.php \
   app/Services/Platforms/Strategies/Contracts/FetchStrategy.php \
   app/Services/Platforms/Strategies/Refresh/OnDemandRefresh.php
php artisan test tests/Feature/Platforms tests/Unit/Platforms
```
Expected: the log shows the Plan-2 commits (`feat(integrations): LinkPayload typed DTO`, `feat(integrations): PlatformDescriptor carries a live connect strategy`, six `refactor(integrations): migrate <x> to GenericPlatformController`); the `ls` lists all four files; the test run is GREEN. **If any is missing, STOP — Plan 2 must land first.**

---

## File Structure

**New:**
- `app/Services/Platforms/Payloads/EmbedPayload.php` — readonly DTO for the `{url, name, thumbnail, embedUrl, link, artistId}` oEmbed payload. `artistId` is Deezer's private re-fetch key (the resource omits it; the DTO carries it so the stored row round-trips).
- `app/Services/Platforms/Strategies/Fetch/FetchShapeException.php` — thrown when a stored payload is missing a key the fetch needs. Mirrors `PlatformRefresher`'s `status='error'` bucket. Consumed by the Plan-6 refresher.
- `app/Services/Platforms/Strategies/Fetch/FetchUnavailableException.php` — thrown when the upstream fetch returns nothing usable. Mirrors `status='unavailable'`.
- `app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php` — shared fetch for spotify + soundcloud (wraps `OEmbedService`, parameterized by the oEmbed-endpoint builder). Mirrors `PlatformRefresher::musicEmbedPayload`.
- `app/Services/Platforms/Strategies/Fetch/DeezerFetch.php` — wraps `DeezerApi`. Mirrors `PlatformRefresher::deezerPayload`.
- `tests/Unit/Platforms/Payloads/EmbedPayloadTest.php`
- `tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php` — runs the real refresher vs. the strategy against the same mock; asserts payload parity + the exception/status mapping.

**Modified:**
- `app/Services/Platforms/Registry/PlatformDescriptor.php` — ADD `payload(string)` setter + `payloadClass()` getter; ADD `fetch(FetchStrategy)` setter + `fetchStrategy()` getter (additive; Plan 1/2 API untouched). Update the `oEmbed()` preset to set `->payload(EmbedPayload::class)`; update the `linkOnly()` preset to set `->payload(LinkPayload::class)`.
- `app/Http/Controllers/Api/Platforms/GenericPlatformController.php` — generalize the READ methods: `selection()` reads via `accountRows()->first()` and the descriptor's `payloadClass()`; ADD `accounts()` + `removeAccount()`; `forget()` switches to `forgetAllConnections()`. `connect()` is unchanged (link-only).
- `app/Providers/PlatformRegistryServiceProvider.php` — attach `OEmbedFetch`/`DeezerFetch` instances to the spotify/soundcloud/deezer descriptors.
- `routes/api/integrations.php` — move spotify/soundcloud/deezer out of `$singleSelection` into a new `$migratedReads` loop whose read routes target `GenericPlatformController` while `/connect` stays on the thin controller.
- `tests/Unit/Platforms/Registry/PlatformDescriptorTest.php` — ADD cases for the `payload()`/`fetch()` setters/getters.
- `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` — strengthen the oembed selection pin to assert the EXACT 5-key JSON, and ADD a spotify `/accounts` list pin (the multi-account read path now served by the generic controller).

**NOT modified / NOT deleted (unlike Plan 2):**
- `SpotifyController`, `SoundcloudController`, `DeezerController` keep `connect()` (+ `platform()`/`resourceClass()`/`supportsMultipleAccounts()`); their inherited read methods become route-bypassed but stay. No controller deletion.
- `SingleSelectionPlatformController` — its read methods are still used by skool/strava/opentable/resdiary/nowbookit (not migrated here). Removal is deferred (Plan 4/later).
- `PlatformRefresher`, `MusicEmbedConnectionResource`, the `ConnectSpotify/Soundcloud/Deezer` Form Requests — untouched.

**Explicitly OUT of scope — deferred (see "Deferred" at the end):** the feed archetype (Plan 3b); picker/shop (Plan 4); bespoke/specials incl. Instagram/Fresha/Shop and google-business auto-sync (Plan 5); the `PlatformRefresher` `match()` → registry rewrite and the `RefreshStrategy` wiring (Plan 6); the `DROP CONSTRAINT` migration (Plan 6); wiring `PlatformInRegistry` into Form Requests; adopting `EmbedPayload` inside `connect()` (read-path only this plan).

---

## Task 1: `PlatformDescriptor` carries a payload class and a fetch strategy

**Files:**
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php`
- Test: `tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`

**Interfaces:**
- Consumes: `FetchStrategy` (Plan 1 contract).
- Produces (added to `PlatformDescriptor`, all additive):
  - `payload(string $payloadClass): self` — fluent setter (stores the DTO class-string).
  - `payloadClass(): ?string` — getter (null until set).
  - `fetch(FetchStrategy $strategy): self` — fluent setter.
  - `fetchStrategy(): ?FetchStrategy` — getter (null for link-only / no-fetch platforms).

> **Why both live on the descriptor:** the generic controller's read path needs to know which DTO hydrates a platform's stored `payload` (`payloadClass()`), and Plan 6's refresher will iterate `$registry->refreshable()` and call `$descriptor->fetchStrategy()->fetch($connection)`. Carrying both keeps "one declaration per platform" (spec §2). Adding them now — even though only `payloadClass()` is consumed this plan — lets the embed descriptors be fully wired so Plan 6 is a pure refresher edit.

- [ ] **Step 1: Write the failing test (append to the existing file)**

Append to `tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`:

```php
it('carries a payload class', function () {
    $d = \App\Services\Platforms\Registry\PlatformDescriptor::make('spotify')
        ->payload(\App\Services\Platforms\Payloads\LinkPayload::class);

    expect($d->payloadClass())->toBe(\App\Services\Platforms\Payloads\LinkPayload::class);
});

it('defaults payloadClass and fetchStrategy to null', function () {
    $d = \App\Services\Platforms\Registry\PlatformDescriptor::make('plain');

    expect($d->payloadClass())->toBeNull();
    expect($d->fetchStrategy())->toBeNull();
});

it('carries a fetch strategy', function () {
    $fetch = new class implements \App\Services\Platforms\Strategies\Contracts\FetchStrategy {
        public function fetch(\App\Models\Core\Site\IntegrationConnection $connection): array
        {
            return ['fetched' => true];
        }
    };

    $d = \App\Services\Platforms\Registry\PlatformDescriptor::make('youtube')->fetch($fetch);

    expect($d->fetchStrategy())->toBe($fetch);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`
Expected: FAIL — `Method ... payload() does not exist` (or `payloadClass()`).

- [ ] **Step 3: Add the setters/getters to `PlatformDescriptor`**

In `app/Services/Platforms/Registry/PlatformDescriptor.php`, add two private properties beside the existing ones (near `$connectStrategy`):

```php
    private ?string $payloadClass = null;

    private ?\App\Services\Platforms\Strategies\Contracts\FetchStrategy $fetchStrategy = null;
```

Add the methods (place them after `connectErrorMessage()`):

```php
    /** The typed DTO that hydrates this platform's stored payload (read boundary). */
    public function payload(string $payloadClass): self
    {
        $this->payloadClass = $payloadClass;

        return $this;
    }

    public function payloadClass(): ?string
    {
        return $this->payloadClass;
    }

    /**
     * The strategy that re-pulls this platform's display snapshot from upstream.
     * Null for link-only / no-fetch platforms. Consumed by Plan 6's registry-driven
     * refresher (`$registry->refreshable()` → `fetchStrategy()->fetch($connection)`).
     */
    public function fetch(\App\Services\Platforms\Strategies\Contracts\FetchStrategy $strategy): self
    {
        $this->fetchStrategy = $strategy;

        return $this;
    }

    public function fetchStrategy(): ?\App\Services\Platforms\Strategies\Contracts\FetchStrategy
    {
        return $this->fetchStrategy;
    }
```

(Prefer a `use App\Services\Platforms\Strategies\Contracts\FetchStrategy;` import at the top and the short name in signatures, matching the file's existing `use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;` style.)

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`
Expected: PASS (existing cases + 3 new).

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Registry/PlatformDescriptor.php tests/Unit/Platforms/Registry/PlatformDescriptorTest.php
git commit -m "feat(integrations): PlatformDescriptor carries payload class + fetch strategy"
```

---

## Task 2: `EmbedPayload` typed DTO

**Files:**
- Create: `app/Services/Platforms/Payloads/EmbedPayload.php`
- Test: `tests/Unit/Platforms/Payloads/EmbedPayloadTest.php`
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php` (set `payload(EmbedPayload::class)` in the `oEmbed()` preset; set `payload(LinkPayload::class)` in the `linkOnly()` preset)

**Interfaces:**
- Produces: `final readonly class EmbedPayload { public ?string $url; public ?string $name; public ?string $thumbnail; public ?string $embedUrl; public ?string $link; public ?string $artistId; public static function fromArray(array): self; public function toArray(): array; }`. `toArray()` returns exactly the 6 keys in that order. `fromArray()` is lenient: missing/non-string → `null`.

> **Why this shape:** `MusicEmbedConnectionResource` (the frozen resource shared by all three) emits exactly `{url, name, thumbnail, embedUrl, link}` (`app/Http/Resources/Platforms/MusicEmbedConnectionResource.php:22-28`). Deezer additionally STORES `artistId` (its `/artist/{id}` re-fetch key, written by `DeezerController::connect` and read by `PlatformRefresher::deezerPayload`) which the resource deliberately omits. The DTO carries `artistId` so a Deezer row round-trips losslessly; the resource still drops it, so the contract is unchanged. This is the spec §8 "DTO carries internal-only fields the Resource omits" pattern.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\Payloads\EmbedPayload;

it('round-trips a full deezer-shaped payload (including the internal artistId)', function () {
    $stored = [
        'url' => 'https://www.deezer.com/artist/123',
        'name' => 'Artist',
        'thumbnail' => 'https://e-cdn.deezer.com/t.jpg',
        'embedUrl' => 'https://widget.deezer.com/widget/dark/artist/123',
        'link' => 'https://www.deezer.com/artist/123',
        'artistId' => '123',
    ];

    expect(EmbedPayload::fromArray($stored)->toArray())->toBe($stored);
});

it('exposes typed properties', function () {
    $p = EmbedPayload::fromArray(['url' => 'https://open.spotify.com/artist/abc', 'embedUrl' => 'https://open.spotify.com/embed/artist/abc']);

    expect($p->url)->toBe('https://open.spotify.com/artist/abc');
    expect($p->embedUrl)->toBe('https://open.spotify.com/embed/artist/abc');
    expect($p->artistId)->toBeNull();
});

it('hydrates leniently — missing keys become null, unknown keys are dropped', function () {
    $p = EmbedPayload::fromArray(['url' => 'https://soundcloud.com/x', '_leak' => 'must-not-survive']);

    expect($p->name)->toBeNull();
    expect($p->toArray())->toBe([
        'url' => 'https://soundcloud.com/x', 'name' => null, 'thumbnail' => null,
        'embedUrl' => null, 'link' => null, 'artistId' => null,
    ]);
    expect($p->toArray())->not->toHaveKey('_leak');
});

it('coerces non-string scalars to null', function () {
    $p = EmbedPayload::fromArray(['artistId' => 123, 'name' => ['nested']]);

    expect($p->artistId)->toBeNull();
    expect($p->name)->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/EmbedPayloadTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Payloads\EmbedPayload" not found`.

- [ ] **Step 3: Write the DTO**

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the oEmbed music archetype (Spotify / SoundCloud / Deezer,
// plus the dormant mixcloud/tidal). Stored shape is {url, name, thumbnail,
// embedUrl, link}; Deezer additionally stores `artistId` as its private re-fetch
// key, which MusicEmbedConnectionResource omits — the DTO carries it so the stored
// row round-trips losslessly while the resource output stays the frozen 5 keys.
// Single home for the tolerant `?? null` hydration previously duplicated across the
// embed controllers, PlatformRefresher::musicEmbedPayload/deezerPayload, and the
// resource (spec §8).
final readonly class EmbedPayload
{
    public function __construct(
        public ?string $url,
        public ?string $name,
        public ?string $thumbnail,
        public ?string $embedUrl,
        public ?string $link,
        public ?string $artistId,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            url: self::stringOrNull($payload['url'] ?? null),
            name: self::stringOrNull($payload['name'] ?? null),
            thumbnail: self::stringOrNull($payload['thumbnail'] ?? null),
            embedUrl: self::stringOrNull($payload['embedUrl'] ?? null),
            link: self::stringOrNull($payload['link'] ?? null),
            artistId: self::stringOrNull($payload['artistId'] ?? null),
        );
    }

    /** @return array{url:?string,name:?string,thumbnail:?string,embedUrl:?string,link:?string,artistId:?string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'thumbnail' => $this->thumbnail,
            'embedUrl' => $this->embedUrl,
            'link' => $this->link,
            'artistId' => $this->artistId,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/EmbedPayloadTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Wire `EmbedPayload` + `LinkPayload` into the descriptor presets**

In `app/Services/Platforms/Registry/PlatformDescriptor.php`, the `linkOnly()` and `oEmbed()` presets currently set label/category/resource/refreshable. Add `->payload(...)` to each so every preset-built descriptor carries its DTO:

```php
    /** Link-only social: store a URL, no fetch, no refresh. */
    public static function linkOnly(string $key, string $label, string $resourceClass): self
    {
        return self::make($key)->label($label)->category(PlatformCategory::Social)
            ->resource($resourceClass)->refreshable(false)
            ->payload(\App\Services\Platforms\Payloads\LinkPayload::class);
    }

    /** oEmbed music embed: resolves name/artwork on refresh. */
    public static function oEmbed(string $key, string $label, string $resourceClass): self
    {
        return self::make($key)->label($label)->category(PlatformCategory::Music)
            ->resource($resourceClass)->refreshable(true)
            ->payload(\App\Services\Platforms\Payloads\EmbedPayload::class);
    }
```

(Add `use` imports for `LinkPayload` and `EmbedPayload` at the top to match the file's import style.) This sets `payloadClass` for all six link-only descriptors (LinkPayload) and all five oEmbed descriptors — spotify, soundcloud, deezer, mixcloud, tidal (EmbedPayload) — in one place.

- [ ] **Step 6: Verify nothing broke (presets are behaviour-neutral until Task 3 consumes them)**

Run: `php artisan test tests/Unit/Platforms/Registry tests/Feature/Platforms/Registry tests/Feature/Platforms/GoldenMaster`
Expected: PASS. (The generic controller does not read `payloadClass()` yet — Task 3 — so the golden master is unaffected; this step just confirms the preset edit compiles and the registry coverage test still enumerates the same keys.)

- [ ] **Step 7: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/EmbedPayload.php tests/Unit/Platforms/Payloads/EmbedPayloadTest.php app/Services/Platforms/Registry/PlatformDescriptor.php
git commit -m "feat(integrations): EmbedPayload DTO + descriptor preset payload classes"
```

---

## Task 3: Generalize `GenericPlatformController` read paths

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/GenericPlatformController.php`
- Test: `tests/Feature/Platforms/GenericLinkControllerTest.php` (must stay green — proves behaviour-equivalence for the already-migrated link platforms; add one explicit assertion)

**Interfaces:**
- Consumes: `PlatformDescriptor::payloadClass()` (Task 1/2), `ManagesIntegrationConnection` trait methods (`accountRows`, `accountsListData`, `forgetAllConnections`, `forgetConnection`, `readConnection` — all already in `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`).
- Produces (added/changed on `GenericPlatformController`):
  - `selection(Request): JsonResponse` — now `['selection' => first-account-or-null]`, hydrated via `$descriptor->payloadClass()`.
  - `accounts(Request): JsonResponse` — `['accounts' => list]`.
  - `removeAccount(Request, string $id): JsonResponse` — 404 `'Account not found.'` when absent, else `['accounts' => list]`.
  - `forget(Request): JsonResponse` — now `forgetAllConnections()`; still returns `['selection' => null]`.

> **Why no `multiAccount` flag is needed:** `SingleSelectionPlatformController::selection()` already reads `accountRows($user)->first()?->payload` for EVERY platform (single or multi) — a single-selection platform stores ONE row under `resource_id = <slug>`, and `accountRows()` includes it (it only excludes `event-`/`link-` prefixed rows). Link-only platforms migrated in Plan 2 store their row under `resource_id = <slug>` via `writeConnection()` too, so `accountRows()->first()` returns it. Therefore switching `selection()` from `readConnection()` to `accountRows()->first()`, and `forget()` from `forgetConnection()` to `forgetAllConnections()`, is **behaviour-equivalent for the link platforms** (one row → same result) and **correct for the multi-account embed platforms** (first of many / clear all). `GenericLinkControllerTest` + the link golden master prove no drift.

- [ ] **Step 1: Add a behaviour-equivalence assertion to `GenericLinkControllerTest` (run first to confirm it currently passes against the OLD controller)**

Append to `tests/Feature/Platforms/GenericLinkControllerTest.php` (it already defines `genericLinkUser(string $handle): User` at line 12 and uses the global `actingAsUser()` helper):

```php
it('forget clears the link connection and selection returns null (behaviour-equivalent under the generalized read path)', function () {
    $user = genericLinkUser('gldforget');

    actingAsUser($user)->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])->assertOk();
    actingAsUser($user)->getJson('/api/platforms/tiktok/selection')->assertOk()
        ->assertJsonPath('selection.username', 'dancer');

    actingAsUser($user)->deleteJson('/api/platforms/tiktok')->assertOk()
        ->assertExactJson(['selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/tiktok/selection')->assertOk()
        ->assertExactJson(['selection' => null]);
});
```

Run: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php`
Expected: PASS against the CURRENT controller (this is the behaviour we must preserve). If the file's user helper has a different name/signature, adapt the seed line — do not invent a new helper.

- [ ] **Step 2: Generalize the controller**

Replace the body of `selection()` and `forget()` and add `accounts()` + `removeAccount()` in `app/Http/Controllers/Api/Platforms/GenericPlatformController.php`. Keep `connect()`, `platform()`, and `descriptor()` unchanged. The new read section:

```php
    // GET /api/platforms/{platform}/selection — the first connected account's
    // selection (or null), hydrated through the descriptor's typed payload DTO.
    // accountRows()->first() matches SingleSelectionPlatformController exactly for
    // both single- and multi-account platforms (the single row is stored under
    // resource_id = <slug>, which accountRows() includes).
    public function selection(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $payload = $this->accountRows($this->currentUser($request))->first()?->payload;

        if ($payload === null) {
            return $this->success(['selection' => null]);
        }

        return $this->success(['selection' => $this->shape($descriptor, $payload)]);
    }

    // GET /api/platforms/{platform}/accounts — every connected account, ordered,
    // each with its public resource_id as `id`.
    public function accounts(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor();

        return $this->success(['accounts' => $this->accountsList($descriptor, $this->currentUser($request))]);
    }

    // DELETE /api/platforms/{platform}/accounts/{id} — remove one account.
    public function removeAccount(Request $request, string $id): JsonResponse
    {
        $descriptor = $this->descriptor();
        $user = $this->currentUser($request);

        if (! $this->accountRows($user)->firstWhere('resource_id', $id)) {
            return $this->error('Account not found.', 404);
        }
        $this->forgetConnection($user, $id);

        return $this->success(['accounts' => $this->accountsList($descriptor, $user)]);
    }

    // DELETE /api/platforms/{platform} — clear every connection for the platform.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetAllConnections($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Hydrate a stored payload through the descriptor's typed DTO, then serialize
    // through its (frozen) resource. The DTO is the single tolerant-hydration home;
    // the resource allowlists its own key subset, so any extra DTO key is dropped.
    private function shape(PlatformDescriptor $descriptor, array $payload): array
    {
        $payloadClass = $descriptor->payloadClass() ?? LinkPayload::class;
        $resourceClass = $descriptor->resourceClass();

        return (new $resourceClass($payloadClass::fromArray($payload)->toArray()))->resolve();
    }

    /** @return list<array<string,mixed>> */
    private function accountsList(PlatformDescriptor $descriptor, \App\Models\Core\User\User $user): array
    {
        return $this->accountsListData($user, fn (array $payload) => $this->shape($descriptor, $payload));
    }
```

Notes for the implementer:
- `connect()` keeps its hard-coded `LinkPayload` (only link platforms route to generic `connect()`); do not change it.
- Add `use App\Models\Core\User\User;` if you reference the FQCN inline rather than importing.
- `selection()`/`accounts()` build the response in the SAME wrapper shape (`['selection' => …]` / `['accounts' => …]`) the per-platform base used — `SingleSelectionPlatformController::selection/accounts/removeAccount` — so the contract is byte-identical.

- [ ] **Step 3: Run the link-only behaviour gate**

Run: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php tests/Feature/Platforms/GoldenMaster`
Expected: PASS — the new `selection()`/`forget()` produce identical output for the link platforms (single row), and the golden-master `link_only` dataset (`tiktok/facebook/x/linkedin/threads/reddit/skool`) is unchanged.

- [ ] **Step 4: Run the full platforms suite (nothing else should move yet)**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: PASS. No embed routes point at the generic controller yet (Task 6), so embed behaviour is untouched here.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/GenericPlatformController.php tests/Feature/Platforms/GenericLinkControllerTest.php
git commit -m "refactor(integrations): generic controller read paths resolve payload class from descriptor"
```

---

## Task 4: Fetch-failure exceptions + `OEmbedFetch` strategy (spotify + soundcloud)

**Files:**
- Create: `app/Services/Platforms/Strategies/Fetch/FetchShapeException.php`
- Create: `app/Services/Platforms/Strategies/Fetch/FetchUnavailableException.php`
- Create: `app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php`
- Test: `tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (attach `OEmbedFetch` to spotify + soundcloud)

**Interfaces:**
- Consumes: `OEmbedService::resolve(string $oembedEndpoint): ?array` (returns `['name'=>?, 'thumbnail'=>?, 'embedUrl'=>?]`), `FetchStrategy` (Plan 1).
- Produces:
  - `class FetchShapeException extends \RuntimeException` — missing-key failures (refresher `status='error'`).
  - `class FetchUnavailableException extends \RuntimeException` — transient/empty upstream (refresher `status='unavailable'`).
  - `final readonly class OEmbedFetch implements FetchStrategy { public function __construct(OEmbedService $oembed, \Closure $endpointFor, string $platform); public function fetch(IntegrationConnection): array; }` — on success returns `[...$payload, name, thumbnail, embedUrl]` exactly as `PlatformRefresher::musicEmbedPayload`.

> **Why exceptions, not a 3-tuple return:** the `FetchStrategy` interface returns a bare `array` (the new payload). `PlatformRefresher`'s private methods return `{payload, error, status}` with three outcomes. The success payload is the array; the two failure outcomes (`error` = bad shape, `unavailable` = transient) become two exception types. Plan 6's refresher rewrite will `try { fetch() } catch (FetchShapeException) {…log+status='error'} catch (FetchUnavailableException) {…status='unavailable'}` — preserving today's behaviour. The existing `OnDemandRefresh`/`ScheduledRefresh` skeletons (which set `status='ok'` unconditionally and are wired to nothing yet) are also rewritten/wrapped in Plan 6; this plan does not touch them.

- [ ] **Step 1: Write the parity test (the proof obligation)**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;

// gmUser()/gmSeed() are loaded globally by tests/Pest.php:72 (it require_once's
// Feature/Platforms/GoldenMaster/golden_master_helpers.php for the whole suite),
// so no local require is needed — same as IntegrationContractGoldenMasterTest.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// The spotify endpoint builder used by both the refresher (PlatformRefresher.php:61)
// and the strategy attached in the service provider — kept identical here.
function spotifyEndpoint(): Closure
{
    return fn (string $link) => 'https://open.spotify.com/oembed?url='.rawurlencode($link);
}

it('OEmbedFetch(spotify) produces the same success payload as the refresher', function () {
    $this->mock(OEmbedService::class, function ($m) {
        $m->shouldReceive('resolve')->andReturn([
            'name' => 'Fresh Name', 'thumbnail' => 'https://i.scdn.co/new.jpg',
            'embedUrl' => 'https://open.spotify.com/embed/artist/abc',
        ]);
    });

    $stored = [
        'url' => 'https://open.spotify.com/artist/abc', 'name' => 'Old', 'thumbnail' => 'https://old.jpg',
        'embedUrl' => 'https://open.spotify.com/embed/artist/abc', 'link' => 'https://open.spotify.com/artist/abc',
    ];

    // Refresher path — the behaviour we must preserve.
    $refresherRow = gmSeed(gmUser('gmspf1'), 'spotify', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    // Strategy path — must equal it.
    $strategyRow = gmSeed(gmUser('gmspf2'), 'spotify', $stored);
    $result = (new OEmbedFetch(app(OEmbedService::class), spotifyEndpoint(), 'spotify'))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
});

it('OEmbedFetch throws FetchShapeException where the refresher records status=error (missing link)', function () {
    $row = gmSeed(gmUser('gmspf3'), 'spotify', ['name' => 'No link here']);

    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmspf4'), 'spotify', ['name' => 'No link here']);
    expect(fn () => (new OEmbedFetch(app(OEmbedService::class), spotifyEndpoint(), 'spotify'))->fetch($strategyRow))
        ->toThrow(FetchShapeException::class);
});

it('OEmbedFetch throws FetchUnavailableException where the refresher records status=unavailable (oEmbed miss)', function () {
    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->andReturnNull());

    $stored = ['url' => 'https://open.spotify.com/artist/abc', 'link' => 'https://open.spotify.com/artist/abc'];

    $row = gmSeed(gmUser('gmspf5'), 'spotify', $stored);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmspf6'), 'spotify', $stored);
    expect(fn () => (new OEmbedFetch(app(OEmbedService::class), spotifyEndpoint(), 'spotify'))->fetch($strategyRow))
        ->toThrow(FetchUnavailableException::class);
});
```

> Note: `PlatformRefresher::refresh()` resolves `OEmbedService` via constructor injection, so `$this->mock(OEmbedService::class)` (a container binding) reaches BOTH the refresher and the `app(OEmbedService::class)` the strategy receives — they share one mock. The refresher's other constructor deps autowire (real, unused for a spotify refresh).

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Strategies\Fetch\OEmbedFetch" not found`.

- [ ] **Step 3: Write the two exceptions**

`app/Services/Platforms/Strategies/Fetch/FetchShapeException.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

// A stored payload is missing a key the fetch needs (link, artistId, handle, …).
// Mirrors PlatformRefresher's status='error' bucket — a data-integrity problem the
// Plan-6 refresher logs loudly (integrations.refresh.bad_shape). Distinct from a
// transient upstream miss (FetchUnavailableException).
class FetchShapeException extends \RuntimeException {}
```

`app/Services/Platforms/Strategies/Fetch/FetchUnavailableException.php`:
```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

// The upstream fetch returned nothing usable (empty scrape, failed API/oEmbed call).
// Mirrors PlatformRefresher's status='unavailable' bucket — recorded quietly, the
// last-known-good payload preserved (no edge-cache purge).
class FetchUnavailableException extends \RuntimeException {}
```

- [ ] **Step 4: Write `OEmbedFetch`** (mirror `PlatformRefresher::musicEmbedPayload`, `PlatformRefresher.php:249-266`)

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Closure;

// Shared fetch for the oEmbed music embeds (Spotify, SoundCloud). Re-resolves
// name + artwork + embed URL from the platform's public oEmbed endpoint; the stored
// link/url is the stable input. Mirrors PlatformRefresher::musicEmbedPayload EXACTLY
// — same link-key precedence (link ?? url), same spread-merge with the existing
// payload — so the Plan-6 refresher swap is behaviour-preserving.
final readonly class OEmbedFetch implements FetchStrategy
{
    /** @param Closure(string):string $endpointFor builds the oEmbed endpoint URL from the stored link */
    public function __construct(
        private OEmbedService $oembed,
        private Closure $endpointFor,
        private string $platform,
    ) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $link = $payload['link'] ?? $payload['url'] ?? null;
        if (! $link) {
            throw new FetchShapeException('missing_key: link');
        }

        $resolved = $this->oembed->resolve(($this->endpointFor)($link));
        if ($resolved === null) {
            throw new FetchUnavailableException("{$this->platform}_oembed_failed");
        }

        return [
            ...$payload,
            'name' => $resolved['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $resolved['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => $resolved['embedUrl'] ?? ($payload['embedUrl'] ?? null),
        ];
    }
}
```

- [ ] **Step 5: Run to verify the parity test passes**

Run: `php artisan test tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Attach `OEmbedFetch` to the spotify + soundcloud descriptors**

In `app/Providers/PlatformRegistryServiceProvider.php`, inside the registry singleton closure (after the oEmbed `foreach` that registers spotify/soundcloud/deezer), resolve `OEmbedService` and attach the fetch strategy with each platform's exact endpoint builder (copied verbatim from `PlatformRefresher.php:61-62`):

```php
            // Attach the live fetch strategies (Plan 3a). Consumed by Plan 6's
            // registry-driven refresher; built eagerly here like the link-only
            // UrlConnect strategies above.
            $oembed = $this->app->make(\App\Services\Platforms\OEmbedService::class);
            $r->get('spotify')->fetch(new \App\Services\Platforms\Strategies\Fetch\OEmbedFetch(
                $oembed, fn (string $link) => 'https://open.spotify.com/oembed?url='.rawurlencode($link), 'spotify',
            ));
            $r->get('soundcloud')->fetch(new \App\Services\Platforms\Strategies\Fetch\OEmbedFetch(
                $oembed, fn (string $link) => 'https://soundcloud.com/oembed?format=json&url='.rawurlencode($link), 'soundcloud',
            ));
```

- [ ] **Step 7: Add a registry assertion that the strategy is attached**

Append to `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`:

```php
it('attaches an OEmbedFetch strategy to the spotify and soundcloud descriptors', function () {
    $registry = app(\App\Services\Platforms\Registry\PlatformRegistry::class);

    expect($registry->get('spotify')->fetchStrategy())
        ->toBeInstanceOf(\App\Services\Platforms\Strategies\Fetch\OEmbedFetch::class);
    expect($registry->get('soundcloud')->fetchStrategy())
        ->toBeInstanceOf(\App\Services\Platforms\Strategies\Fetch\OEmbedFetch::class);
});
```

Run: `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/FetchShapeException.php \
        app/Services/Platforms/Strategies/Fetch/FetchUnavailableException.php \
        app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php \
        app/Providers/PlatformRegistryServiceProvider.php \
        tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php \
        tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "feat(integrations): OEmbedFetch strategy + fetch-failure exception taxonomy"
```

---

## Task 5: `DeezerFetch` strategy

**Files:**
- Create: `app/Services/Platforms/Strategies/Fetch/DeezerFetch.php`
- Test: extend `tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (attach `DeezerFetch` to deezer)

**Interfaces:**
- Consumes: `DeezerApi::fetchArtist(string $id): ?array` (returns `['name'=>?, 'thumbnail'=>?, 'link'=>?]`), `DeezerApi::embedUrlForArtist(string $id): string` (static), `FetchStrategy`.
- Produces: `final readonly class DeezerFetch implements FetchStrategy { public function __construct(DeezerApi $deezer); public function fetch(IntegrationConnection): array; }` — on success returns `[...$payload, name, thumbnail, embedUrl]` exactly as `PlatformRefresher::deezerPayload`.

> **Edge note (asymmetry to preserve):** Deezer's missing-key failure uses `artistId` (`PlatformRefresher::deezerPayload`, `PlatformRefresher.php:322-341`) and is `status='error'` → `FetchShapeException`. A failed `fetchArtist` is `status='unavailable'` → `FetchUnavailableException`. The `embedUrl` is always RECOMPUTED via the static `DeezerApi::embedUrlForArtist($id)` (self-heals legacy rows) — so the strategy must call the static, not preserve the stored `embedUrl`.

- [ ] **Step 1: Add the Deezer parity cases to `EmbedFetchParityTest.php`**

```php
use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\Strategies\Fetch\DeezerFetch;

it('DeezerFetch produces the same success payload as the refresher (embedUrl recomputed)', function () {
    $this->mock(DeezerApi::class, function ($m) {
        $m->shouldReceive('fetchArtist')->with('123')->andReturn([
            'name' => 'Fresh', 'thumbnail' => 'https://e-cdn.deezer.com/new.jpg', 'link' => 'https://www.deezer.com/artist/123',
        ]);
    });

    $stored = [
        'url' => 'https://www.deezer.com/artist/123', 'artistId' => '123', 'name' => 'Old',
        'thumbnail' => 'https://old.jpg', 'embedUrl' => 'https://stale', 'link' => 'https://www.deezer.com/artist/123',
    ];

    $refresherRow = gmSeed(gmUser('gmdz1'), 'deezer', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmdz2'), 'deezer', $stored);
    $result = (new DeezerFetch(app(DeezerApi::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    // The recompute self-heals the stale stored embedUrl.
    expect($result['embedUrl'])->toBe(DeezerApi::embedUrlForArtist('123'));
});

it('DeezerFetch throws FetchShapeException when artistId is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmdz3'), 'deezer', ['url' => 'https://www.deezer.com/artist/123']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmdz4'), 'deezer', ['url' => 'https://www.deezer.com/artist/123']);
    expect(fn () => (new DeezerFetch(app(DeezerApi::class)))->fetch($strategyRow))
        ->toThrow(FetchShapeException::class);
});

it('DeezerFetch throws FetchUnavailableException when fetchArtist returns null (status=unavailable)', function () {
    $this->mock(DeezerApi::class, fn ($m) => $m->shouldReceive('fetchArtist')->andReturnNull());

    $stored = ['artistId' => '123', 'url' => 'https://www.deezer.com/artist/123'];
    $row = gmSeed(gmUser('gmdz5'), 'deezer', $stored);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmdz6'), 'deezer', $stored);
    expect(fn () => (new DeezerFetch(app(DeezerApi::class)))->fetch($strategyRow))
        ->toThrow(FetchUnavailableException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Strategies\Fetch\DeezerFetch" not found`.

- [ ] **Step 3: Write `DeezerFetch`** (mirror `PlatformRefresher::deezerPayload`)

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-resolves a Deezer artist's name + artwork by stored artistId. The link is
// stable; embedUrl is always recomputed via DeezerApi::embedUrlForArtist (self-heals
// rows stored before the /top_tracks fix). Mirrors PlatformRefresher::deezerPayload
// EXACTLY so the Plan-6 refresher swap is behaviour-preserving.
final readonly class DeezerFetch implements FetchStrategy
{
    public function __construct(private DeezerApi $deezer) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $id = $payload['artistId'] ?? null;
        if (! $id) {
            throw new FetchShapeException('missing_key: artistId');
        }

        $artist = $this->deezer->fetchArtist((string) $id);
        if ($artist === null) {
            throw new FetchUnavailableException('deezer_fetch_failed');
        }

        return [
            ...$payload,
            'name' => $artist['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $artist['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => DeezerApi::embedUrlForArtist((string) $id),
        ];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php`
Expected: PASS (6 tests total).

- [ ] **Step 5: Attach `DeezerFetch` to the deezer descriptor**

In `app/Providers/PlatformRegistryServiceProvider.php`, beside the `OEmbedFetch` attachments from Task 4:

```php
            $r->get('deezer')->fetch(new \App\Services\Platforms\Strategies\Fetch\DeezerFetch(
                $this->app->make(\App\Services\Platforms\DeezerApi::class),
            ));
```

Extend the RegistryCoverageTest assertion from Task 4 (or add a sibling) to cover deezer:
```php
expect($registry->get('deezer')->fetchStrategy())
    ->toBeInstanceOf(\App\Services\Platforms\Strategies\Fetch\DeezerFetch::class);
```

Run: `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/DeezerFetch.php \
        app/Providers/PlatformRegistryServiceProvider.php \
        tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php \
        tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "feat(integrations): DeezerFetch strategy"
```

---

## Task 6: Migrate spotify + soundcloud + deezer read paths onto the generic controller

**Files:**
- Modify: `routes/api/integrations.php` (move the 3 slugs from `$singleSelection` into a new `$migratedReads` loop)
- Modify: `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` (tighten the oembed selection pin to exact JSON; add a spotify `/accounts` pin)

> **Why one task for all three:** spotify, soundcloud, and deezer share the SAME resource (`MusicEmbedConnectionResource`), the SAME payload DTO (`EmbedPayload`), and the SAME multi-account read shape. Their read-path migration is identical except the slug, so a single golden-master run covers all three. (Contrast Plan 2's per-platform link tasks, where each platform had a distinct normalizer + 422 message.)

**Interfaces:**
- Consumes: the generalized `GenericPlatformController` (Task 3), `EmbedPayload` on the descriptors (Task 2).
- Produces: spotify/soundcloud/deezer `GET /selection`, `GET /accounts`, `DELETE /accounts/{id}`, `DELETE /` served by `GenericPlatformController`; `POST /connect` unchanged on the thin controllers.

- [ ] **Step 1: Tighten + extend the golden master FIRST (red, capturing the exact target contract)**

In `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`, replace the loose body of the `it('freezes oembed selection contract', …)` test with an EXACT-JSON assertion (the existing dataset `oembed` already has spotify/soundcloud/deezer seeds):

```php
it('freezes oembed selection contract', function (string $platform, array $stored) {
    $user = gmUser("gm{$platform}");
    gmSeed($user, $platform, [...$stored, '_leak' => 'must-not-appear']);

    $selection = actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->json('selection');

    // EmbedPayload → MusicEmbedConnectionResource emits exactly these 5 keys, _leak stripped.
    expect($selection)->toEqual([
        'url' => $stored['url'], 'name' => $stored['name'], 'thumbnail' => $stored['thumbnail'],
        'embedUrl' => $stored['embedUrl'], 'link' => $stored['link'],
    ]);
})->with('oembed');
```

Add a multi-account `/accounts` pin (spotify is in `$multiAccount`):

```php
it('freezes the spotify accounts list contract', function () {
    $user = gmUser('gmspacc');
    gmSeed($user, 'spotify', [
        'url' => 'https://open.spotify.com/artist/abc', 'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg',
        'embedUrl' => 'https://open.spotify.com/embed/artist/abc', 'link' => 'https://open.spotify.com/artist/abc',
        '_leak' => 'x',
    ], 'acct-'.substr(sha1('https://open.spotify.com/artist/abc'), 0, 16));

    $accounts = actingAsUser($user)->getJson('/api/platforms/spotify/accounts')->assertOk()->json('accounts');

    expect($accounts)->toHaveCount(1);
    expect($accounts[0])->not->toHaveKey('_leak');
    expect($accounts[0]['id'])->toBe('acct-'.substr(sha1('https://open.spotify.com/artist/abc'), 0, 16));
    expect($accounts[0]['url'])->toBe('https://open.spotify.com/artist/abc');
    expect($accounts[0]['embedUrl'])->toBe('https://open.spotify.com/embed/artist/abc');
});
```

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`
Expected: still PASS — the OLD `SingleSelectionPlatformController` read path already produces this exact shape (the tighten is a no-op against current behaviour; the `/accounts` pin exercises the existing route). This locks the target before the route flip.

- [ ] **Step 2: Re-point the read routes** in `routes/api/integrations.php`

Remove `'spotify'`, `'soundcloud'`, `'deezer'` from the `$singleSelection` array (lines ~263-279) and from the `$multiAccount` array (line ~283) — leaving `twitch`, `pinterest`, `skool`, `strava`, `google-business`, `opentable`, `resdiary`, `nowbookit` in `$singleSelection`. Then add a new loop BELOW the `$singleSelection` loop:

```php
    // Migrated embed read paths (Plan 3a). connect() stays on the thin controller
    // (it fetches on connect); selection/accounts/forget are served by the
    // registry-driven GenericPlatformController via the platform route default.
    // URIs are unchanged from the $singleSelection version, so the golden-master
    // net-completeness count (52) is unaffected.
    $migratedReads = [
        'spotify' => SpotifyController::class,
        'soundcloud' => SoundcloudController::class,
        'deezer' => DeezerController::class,
    ];
    foreach ($migratedReads as $slug => $connectController) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($connectController, $slug) {
                Route::post('/connect', [$connectController, 'connect']);
                Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $slug);
                Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', $slug);
                Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                    ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', $slug);
                Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', $slug);
            });
    }
```

(`GenericPlatformController`, `SpotifyController`, `SoundcloudController`, `DeezerController` are already imported at the top of the file.)

- [ ] **Step 3: Run the golden master + the embed feature tests + the net guard**

Run: `php artisan test tests/Feature/Platforms/GoldenMaster tests/Feature/Platforms/IntegrationsV3ConnectionTest.php tests/Feature/Platforms/PlatformResourceContractTest.php`
Expected: PASS. The `/connect` tests still hit the thin controllers; `/selection` + `/accounts` now hit the generic controller and produce byte-identical JSON; the net-completeness guard still reports 52.

- [ ] **Step 4: Run the full suite**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add routes/api/integrations.php tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "refactor(integrations): migrate spotify/soundcloud/deezer read paths to GenericPlatformController"
```

---

## Task 7: Confirm mixcloud + tidal are correctly assigned (dormant, no migration)

**Files:**
- Test: `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` (add an assignment assertion)

> **Ground truth (do not fabricate routes/controllers for these):** `mixcloud` and `tidal` are registered as `oEmbed` descriptors (`PlatformRegistryServiceProvider.php:78-79`) with `->refreshable(false)`, sharing `MusicEmbedConnectionResource`. They were REMOVED from the DB CHECK constraint (`supabase/migrations/20260611070000_integrations_remove_tidal.sql`, `…080000_integrations_remove_mixcloud.sql`), have NO controller, and NO route (the `integrations.php` route map lists only spotify/soundcloud/deezer for the embed cluster). They are dormant registry identities. So for this archetype they get: `EmbedPayload` (already set by the `oEmbed()` preset in Task 2), `MusicEmbedConnectionResource` (already), `refreshable(false)` (already), NO fetch strategy, NO route work. This task only PROVES the assignment; it writes no production code.

- [ ] **Step 1: Add the assignment assertion**

Append to `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`:

```php
it('assigns the dormant mixcloud/tidal embeds EmbedPayload with no fetch strategy', function () {
    $registry = app(\App\Services\Platforms\Registry\PlatformRegistry::class);

    foreach (['mixcloud', 'tidal'] as $key) {
        $d = $registry->get($key);
        expect($d)->not->toBeNull();
        expect($d->payloadClass())->toBe(\App\Services\Platforms\Payloads\EmbedPayload::class);
        expect($d->resourceClass())->toBe(\App\Http\Resources\Platforms\MusicEmbedConnectionResource::class);
        expect($d->isRefreshable())->toBeFalse();
        expect($d->fetchStrategy())->toBeNull(); // dormant — no upstream fetch, no routes
    }
});

it('does not register routes for the dormant mixcloud/tidal embeds', function () {
    $uris = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());

    expect($uris->contains(fn ($u) => str_contains($u, 'platforms/mixcloud')))->toBeFalse();
    expect($uris->contains(fn ($u) => str_contains($u, 'platforms/tidal')))->toBeFalse();
});
```

- [ ] **Step 2: Run**

Run: `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "test(integrations): pin dormant mixcloud/tidal embed assignment"
```

---

## Task 8: Embed archetype verification

**Files:** none (verification only).

- [ ] **Step 1: Full platforms suite**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: GREEN.

- [ ] **Step 2: Net-completeness invariant**

Confirm `IntegrationContractGoldenMasterTest`'s net guard still asserts `->toBe(52)` and passes (the route re-points changed controllers, not URIs).

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`
Expected: GREEN; the net test reports 52.

- [ ] **Step 3: Full suite + Pint**

Run:
```bash
php artisan test
php artisan pint --dirty
```
Expected: GREEN suite; Pint reports no changes (all touched files already formatted at commit time).

- [ ] **Step 4: Final confirmation checklist (no code)**

Confirm, by inspection:
- `EmbedPayload` carries `artistId` and round-trips; `MusicEmbedConnectionResource` is unchanged.
- spotify/soundcloud/deezer `/selection` + `/accounts` resolve through `GenericPlatformController`; `/connect` still on the thin controllers.
- spotify/soundcloud/deezer descriptors carry an `OEmbedFetch`/`DeezerFetch`; mixcloud/tidal carry `EmbedPayload` + no fetch.
- `PlatformRefresher` is UNCHANGED (git diff shows no edits to it).
- The two fetch exceptions exist and are thrown where the refresher records `error`/`unavailable`.

This plan does NOT wire the fetch strategies into the refresher — that is Plan 6.

---

## Deferred (NOT in this plan)

- **The feed archetype** (youtube, youtube-music, vimeo, bandcamp, twitch, pinterest, apple-music, apple-podcast, google-business) + `FeedPayload` → **Plan 3b** (`2026-06-28-platform-integrations-feed.md`), which depends on this plan's merged descriptor `payload()`/`fetch()` methods, generalized generic controller, and the `FetchShapeException`/`FetchUnavailableException` taxonomy.
- **Picker / shop archetypes** (fresha, square, opentable, resdiary, nowbookit, shop) + `SelectionPayload`/`ShopPayload` → Plan 4.
- **Bespoke / specials** (Instagram async, Fresha multi-step, Shop multi-brand, google-business auto-sync `/synced`, events smart-detect) → Plan 5.
- **`PlatformRefresher` `match()` → registry iteration** + wiring `OnDemandRefresh`/`ScheduledRefresh` (catching `FetchShapeException`/`FetchUnavailableException`), the `ProviderDetector` rewrite, and the `DROP CONSTRAINT` migration → Plan 6.
- **Adopting `EmbedPayload` inside the thin controllers' `connect()`** (this plan migrates the READ path only; connect storage is unchanged and already contract-frozen).
- **Removing the now-route-bypassed read methods from `SingleSelectionPlatformController`** (still used by skool/strava/opentable/resdiary/nowbookit) → after Plan 4.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-28-platform-integrations-embed.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
