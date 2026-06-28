# Platform Integrations — Scraped/API Feed Archetype (Plan 3b of N)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the scraped/API-feed archetype onto the registry spine: add a single `FeedPayload` typed DTO covering the 8 item/card feed platforms (youtube, youtube-music, vimeo, bandcamp, twitch, pinterest, apple-music, apple-podcast), route their selection/accounts read paths through `GenericPlatformController`, and build the 8 per-platform `FetchStrategy` implementations + a `GoogleBusinessFetch` — each parity-tested against the current `PlatformRefresher` so the Plan-6 refresher swap is provably behaviour-preserving — with the API contract frozen byte-for-byte throughout.

**Architecture:** Second half of the original "Plan 3 (embed + feed)" (see Plan 3a's "Why this is split"). It reuses everything 3a built: the descriptor's `payload()`/`fetch()` methods, the generalized `GenericPlatformController` read paths, and the `FetchShapeException`/`FetchUnavailableException` taxonomy. The thin/bespoke feed controllers (`YoutubeController`, `YoutubeMusicController`, `VimeoController`, `BandcampController`, `AppleController`, `TwitchController`, `PinterestController`) **keep `connect()` and their picker steps (`/recent`, `/highlights`, apple's dual music/podcast)**; only their selection/accounts read routes re-point to the generic controller. No controller is deleted this plan.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 + PHPUnit, SQLite in-memory for tests, Supabase/Postgres in prod.

**Design spec:** `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md` (§6 ② FetchStrategy, §7 scraped/API-feed archetype row + "specials" note, §8 typed payloads, §10 strangler migration, §11 testing, §15 edge-platform open questions).

**Builds on (MUST be merged first):**
- Plan 3a — `docs/superpowers/plans/2026-06-28-platform-integrations-embed.md`. This plan **depends** on 3a's merged changes:
  - `PlatformDescriptor::payload()/payloadClass()` and `fetch()/fetchStrategy()`.
  - The generalized `GenericPlatformController` (descriptor-resolved `payloadClass()`, `accounts()`/`removeAccount()`, `selection()` via `accountRows()->first()`, `forget()` via `forgetAllConnections()`).
  - `app/Services/Platforms/Strategies/Fetch/{FetchShapeException,FetchUnavailableException}.php`.
  - The `$migratedReads` route loop in `routes/api/integrations.php`.
- Plan 1 + Plan 2 (registry spine + link-only toolkit), also merged.

## Global Constraints

- **No Laravel migrations.** No schema change this plan (the `DROP CONSTRAINT` is Plan 6). The composer guard rejects Laravel migrations regardless.
- **API contract is FROZEN — byte-for-byte.** Every route URI, JSON shape, and error string stays identical. `IntegrationContractGoldenMasterTest`, `PlatformResourceContractTest`, `IntegrationsV3ConnectionTest`, `ScraperPlatformsConnectionTest`, and `IntegrationsV4AdditionsTest` must stay green after every task. No assertion in those files may be loosened.
- **The net-completeness count stays `52`.** `IntegrationContractGoldenMasterTest` (~line 184) asserts `expect($readRoutes->count())->toBe(52)`. Every route change re-points an existing `api/platforms/` GET URI to a different controller WITHOUT changing the URI — and pinterest (single-account) must NOT gain an `/accounts` route. If the count moves, a route was added/removed — stop and reconcile before touching the number.
- **Behaviour-preserving fetch strategies (the proof obligation).** Each `FetchStrategy` is adapted from a `PlatformRefresher` private method and MUST produce the byte-identical success payload, asserted by a parity test that runs BOTH `PlatformRefresher::refresh()` and the strategy against the same mocked upstream and compares the resulting payload — plus asserts the `FetchShapeException`/`FetchUnavailableException` mapping against the refresher's recorded `last_refresh_status` (spec §11). The strategy is NOT wired into the refresher — that swap is Plan 6.
- **`PlatformRefresher` is READ-ONLY.** Do not edit it. Mirror its private methods exactly (including the `[...$payload, …]` spread that preserves curated highlights and the per-platform key precedence).
- **Resource classes for all responses.** The generic controller serializes through each descriptor's `resourceClass()` (the per-platform feed resources, unchanged).
- **Authorization via the trait chokepoint.** `ManagesIntegrationConnection` runs `authorizeForUser($user, …)` on every read/write/delete. Never add inline 403 aborts.
- **Tests run on SQLite; prod is Postgres.** All new code is app-level/engine-agnostic. Use `setupUsersTable()`/`setupSitesTable()`/`actingAsUser()` and the golden-master `gmUser()`/`gmSeed()` helpers.
- **Pint clean.** `php artisan pint --dirty` before every commit; never reformat untouched files.
- **Commit prefixes:** `feat(integrations):` for new DTO/strategies, `refactor(integrations):` for a platform read-path migration, `test(integrations):` for test-only additions.

---

## Prerequisite check (run before Task 1)

- [ ] **Confirm Plan 3a is merged and its spine extensions exist**

Run:
```bash
git fetch && git pull && git log --oneline -12
ls app/Services/Platforms/Payloads/EmbedPayload.php \
   app/Services/Platforms/Strategies/Fetch/FetchShapeException.php \
   app/Services/Platforms/Strategies/Fetch/FetchUnavailableException.php \
   app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php
php artisan tinker --execute="echo method_exists(App\Services\Platforms\Registry\PlatformDescriptor::class,'payloadClass') ? 'OK payloadClass' : 'MISSING'; echo PHP_EOL; echo method_exists(App\Http\Controllers\Api\Platforms\GenericPlatformController::class,'accounts') ? 'OK accounts' : 'MISSING';"
php artisan test tests/Feature/Platforms tests/Unit/Platforms
```
Expected: the log shows the Plan-3a commits (`feat(integrations): EmbedPayload DTO …`, `feat(integrations): OEmbedFetch strategy …`, `refactor(integrations): generic controller read paths resolve payload class …`, `refactor(integrations): migrate spotify/soundcloud/deezer read paths …`); the `ls` lists all four files; tinker prints `OK payloadClass` and `OK accounts`; the suite is GREEN. **If any is missing, STOP — Plan 3a must land first.**

---

## File Structure

**New:**
- `app/Services/Platforms/Payloads/FeedPayload.php` — one wide `readonly` DTO covering the 8 item/card feed platforms (18 nullable keys). The typed read/hydration boundary.
- `app/Services/Platforms/Strategies/Fetch/YoutubeFetch.php` — mirrors `PlatformRefresher::youtubePayload`.
- `app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php` — mirrors `youtubeMusicPayload`.
- `app/Services/Platforms/Strategies/Fetch/VimeoFetch.php` — mirrors `vimeoPayload`.
- `app/Services/Platforms/Strategies/Fetch/BandcampFetch.php` — mirrors `bandcampPayload`.
- `app/Services/Platforms/Strategies/Fetch/TwitchFetch.php` — mirrors `twitchPayload`.
- `app/Services/Platforms/Strategies/Fetch/PinterestFetch.php` — mirrors `pinterestPayload`.
- `app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php` — mirrors `appleMusicPayload`.
- `app/Services/Platforms/Strategies/Fetch/ApplePodcastFetch.php` — mirrors `applePodcastPayload`.
- `app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php` — mirrors `googleBusinessPayload` (incl. the freshness short-circuit + the `missing_place_id` → `unavailable` asymmetry).
- `tests/Unit/Platforms/Payloads/FeedPayloadTest.php`
- `tests/Feature/Platforms/Strategies/FeedFetchParityTest.php` — refresher-vs-strategy parity + exception/status mapping, per platform.

**Modified:**
- `app/Providers/PlatformRegistryServiceProvider.php` — set `->payload(FeedPayload::class)` on the 8 feed descriptors (NOT google-business — see Task 9); attach each `*Fetch` strategy.
- `routes/api/integrations.php` — re-point the read routes of youtube/youtube-music/vimeo/bandcamp (their own groups) and twitch/pinterest (move into `$migratedReads`, which is refactored to carry a per-entry `multi` flag) and apple (its `/music/*` + `/podcast/*` read routes) to `GenericPlatformController`. google-business stays on `SingleSelectionPlatformController` (read path deferred to Plan 5 — Task 9 builds only its fetch strategy).
- `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` — add selection/accounts pins for each migrated feed read path.

**NOT modified / NOT deleted:**
- The feed controllers keep `connect()` + `/recent` + `/highlights` (apple keeps its dual music/podcast connect + recent + highlights + the `forget*` DELETEs).
- `PlatformRefresher`, all feed resources (`Youtube*/Vimeo*/Bandcamp*/Twitch*/Pinterest*/AppleMusic*/ApplePodcast*/TileConnectionResource`), all `Connect*Request` form requests — untouched.

**Explicitly OUT of scope — deferred (see "Deferred"):** google-business's read-path/payload migration + its `/synced` + `/synced/apply` + auto-sync (Plan 5 specials); Instagram/Fresha/Shop bespoke (Plan 5); events (eventbrite/humanitix/events-custom — Plan 4/5); picker/shop (Plan 4); the `PlatformRefresher` `match()` rewrite + `RefreshStrategy` wiring + `DROP CONSTRAINT` (Plan 6); adopting `FeedPayload` inside `connect()`/`recent`/`highlights` (read-path only this plan).

---

## Why GoogleBusiness gets a fetch strategy but NOT a payload/read-path migration

`google-business` is listed in the spec's scraped/API-feed row, but it does **not** fit the flat `FeedPayload` model, and it is also a spec §7 "special":

1. **Its resource emits a VARIABLE key set.** `GoogleBusinessConnectionResource::toArray` returns the 5 base keys plus `...array_intersect_key($this->resource, array_flip(ENRICHMENT_KEYS))` (`app/Http/Resources/Platforms/GoogleBusinessConnectionResource.php:38-45`) — ~21 enrichment keys emitted ONLY when present. A flat `FeedPayload` whose `toArray()` always emits its canonical keys (with nulls for absent ones) would inject ~21 `null` enrichment keys into a legacy 5-key Google Business row → **golden-master contract drift**. The other 8 feed resources allowlist their subset key-by-key, so canonical nulls are harmless; Google Business's `array_intersect_key` makes them harmful.
2. **It is genuinely a "special."** It has bespoke `/synced` + `/synced/apply` routes (`integrations.php:324-328`), an auto-sync that seeds Instagram/OpenTable/Uber-Eats rows (`GoogleBusinessAutoSync`), and a connect flow that writes a variable shape — all of which the spec §7 calls out as special behaviour handled with the rest of the specials.

Therefore: this plan **builds + parity-tests + attaches `GoogleBusinessFetch`** (the fetch is listed for this archetype and is tractable), but **defers google-business's payload DTO + read-path migration to Plan 5**, where its conditional-emission resource and auto-sync are handled together. google-business stays on `SingleSelectionPlatformController` for now (Task 9 builds only its fetch strategy; Task 10 is verification-only). This is the spec §15 / "correct archetype assignment for edge platforms" decision; the independent reviewer should confirm it.

---

## Task 1: `FeedPayload` typed DTO

**Files:**
- Create: `app/Services/Platforms/Payloads/FeedPayload.php`
- Test: `tests/Unit/Platforms/Payloads/FeedPayloadTest.php`

**Interfaces:**
- Produces: `final readonly class FeedPayload` with 18 nullable typed properties — the union of every key the 8 feed resources read:
  `?string $handle, $url, $channelId, $apiPath, $input, $login, $username, $artist, $name, $description, $link, $thumbnail, $image, $releaseDate; int|string|null $followers; ?array $latest, $items, $highlights;`
  plus `public static function fromArray(array): self` (lenient: missing/wrong-type scalars → null; `latest`/`items`/`highlights` preserved as arrays or null) and `public function toArray(): array` (emits all 18 keys in declaration order).

> **Why one wide DTO, and why resource-output equivalence (not strict round-trip):** the spec mandates ONE `FeedPayload` per archetype, but the 8 feed payloads are heterogeneous subsets (Twitch stores 5 keys, Apple Podcasts 8, YouTube Music carries an internal `channelId`). A flat DTO can't satisfy `fromArray(toArray(x)) === x` for a partial `x` (it normalizes to the full key set). The contract is instead frozen by **resource-output equivalence**: for each platform's representative payload, `Resource(FeedPayload::fromArray($p)->toArray())` must equal `Resource($p)` — which holds because every feed resource allowlists its own key subset, dropping the extra canonical nulls (`channelId`/`apiPath` are internal and emitted by no resource; the Tile/explicit resources `?? null` their fields). The internal `channelId`/`apiPath` ARE carried so the fetch strategies (and Plan 6) can read them typed. **GoogleBusiness is excluded** (see the section above).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Http\Resources\Platforms\PinterestConnectionResource;
use App\Http\Resources\Platforms\TwitchConnectionResource;
use App\Http\Resources\Platforms\VimeoConnectionResource;
use App\Http\Resources\Platforms\YoutubeConnectionResource;
use App\Http\Resources\Platforms\YoutubeMusicConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;

it('exposes typed properties incl. internal channelId/apiPath and array tiles', function () {
    $p = FeedPayload::fromArray([
        'channelId' => 'UC123', 'apiPath' => 'patagonia',
        'latest' => ['videoId' => 'v1'], 'items' => [['id' => 1]], 'highlights' => [],
        'followers' => 4200,
    ]);

    expect($p->channelId)->toBe('UC123');
    expect($p->apiPath)->toBe('patagonia');
    expect($p->latest)->toBe(['videoId' => 'v1']);
    expect($p->items)->toBe([['id' => 1]]);
    expect($p->highlights)->toBe([]);
    expect($p->followers)->toBe(4200);
});

it('hydrates leniently — missing keys null, unknown keys dropped, non-array tiles null', function () {
    $p = FeedPayload::fromArray(['handle' => 'mychannel', 'latest' => 'not-an-array', '_leak' => 'x']);

    expect($p->handle)->toBe('mychannel');
    expect($p->name)->toBeNull();
    expect($p->latest)->toBeNull();
    expect($p->toArray())->not->toHaveKey('_leak');
    expect(array_keys($p->toArray()))->toBe([
        'handle', 'url', 'channelId', 'apiPath', 'input', 'login', 'username', 'artist',
        'name', 'description', 'link', 'thumbnail', 'image', 'releaseDate', 'followers',
        'latest', 'items', 'highlights',
    ]);
});

// Resource-output equivalence: feeding the DTO-normalized array to each feed
// resource yields the SAME JSON as feeding the raw stored payload. This is the
// contract-freeze property (the golden master proves it again at the HTTP layer).
dataset('feed_payloads', [
    'youtube' => [YoutubeConnectionResource::class, [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [],
    ]],
    'youtube-music' => [YoutubeMusicConnectionResource::class, [
        'url' => 'https://music.youtube.com/channel/UC', 'channelId' => 'UC', 'name' => 'Artist',
        'thumbnail' => 't', 'link' => 'https://music.youtube.com/channel/UC',
        'latest' => ['itemId' => 'i1'], 'items' => [['itemId' => 'i1']], 'highlights' => [],
    ]],
    'vimeo' => [VimeoConnectionResource::class, [
        'url' => 'https://vimeo.com/x', 'apiPath' => 'x', 'name' => 'Pat', 'thumbnail' => 't',
        'link' => 'https://vimeo.com/x', 'latest' => ['id' => 1], 'items' => [['id' => 1]], 'highlights' => [],
    ]],
    'bandcamp' => [BandcampConnectionResource::class, [
        'url' => 'https://x.bandcamp.com', 'artist' => 'X', 'name' => 'Album', 'thumbnail' => 't',
        'link' => 'l', 'latest' => ['id' => 1], 'highlights' => [],
    ]],
    'twitch' => [TwitchConnectionResource::class, [
        'url' => 'https://www.twitch.tv/x', 'login' => 'x', 'name' => 'X', 'image' => 'i', 'description' => 'bio',
    ]],
    'pinterest' => [PinterestConnectionResource::class, [
        'url' => 'https://www.pinterest.com/x/', 'username' => 'x', 'name' => 'X', 'image' => 'i',
        'followers' => 4200, 'latest' => ['id' => 1], 'items' => [['id' => 1]],
    ]],
    'apple-music' => [AppleMusicConnectionResource::class, [
        'input' => 'in', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l',
        'latest' => ['id' => 1], 'highlights' => [],
    ]],
    // apple-podcast is the ONLY feed resource emitting description AND releaseDate in
    // its flat fields — it must be in the dataset so the union-completeness guard
    // exercises that key combination.
    'apple-podcast' => [ApplePodcastConnectionResource::class, [
        'input' => 'in', 'name' => 'Show', 'thumbnail' => 't', 'description' => 'desc', 'releaseDate' => '2026-01-01',
        'link' => 'l', 'latest' => ['id' => 1], 'highlights' => [],
    ]],
]);

it('is resource-output-equivalent to the raw payload', function (string $resourceClass, array $stored) {
    $viaDto = (new $resourceClass(FeedPayload::fromArray($stored)->toArray()))->resolve();
    $viaRaw = (new $resourceClass($stored))->resolve();

    expect($viaDto)->toEqual($viaRaw);
})->with('feed_payloads');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/FeedPayloadTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Payloads\FeedPayload" not found`.

- [ ] **Step 3: Write the DTO**

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the scraped/API-feed archetype — ONE DTO spanning the 8 feed
// platforms (youtube, youtube-music, vimeo, bandcamp, twitch, pinterest, apple-music,
// apple-podcast). Each platform stores a SUBSET of these keys; this is their union.
// `channelId` (YouTube Music) and `apiPath` (Vimeo) are private re-fetch inputs the
// resources never emit — carried so the fetch strategies + Plan 6's refresher read
// them typed. `latest`/`items`/`highlights` hold nested scraper items, passed through
// verbatim. Single home for the tolerant `?? null` hydration scattered across the
// controllers, PlatformRefresher's per-platform methods, and the resources (spec §8).
//
// Contract guarantee: every feed resource allowlists its own key subset, so the
// canonical-null keys this DTO adds are dropped on serialization — Resource(fromArray
// (raw)->toArray()) === Resource(raw). GoogleBusiness is deliberately NOT covered here
// (its resource emits a variable key set via array_intersect_key; see plan §"Why
// GoogleBusiness…").
final readonly class FeedPayload
{
    public function __construct(
        public ?string $handle,
        public ?string $url,
        public ?string $channelId,
        public ?string $apiPath,
        public ?string $input,
        public ?string $login,
        public ?string $username,
        public ?string $artist,
        public ?string $name,
        public ?string $description,
        public ?string $link,
        public ?string $thumbnail,
        public ?string $image,
        public ?string $releaseDate,
        public int|string|null $followers,
        public ?array $latest,
        public ?array $items,
        public ?array $highlights,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            handle: self::stringOrNull($payload['handle'] ?? null),
            url: self::stringOrNull($payload['url'] ?? null),
            channelId: self::stringOrNull($payload['channelId'] ?? null),
            apiPath: self::stringOrNull($payload['apiPath'] ?? null),
            input: self::stringOrNull($payload['input'] ?? null),
            login: self::stringOrNull($payload['login'] ?? null),
            username: self::stringOrNull($payload['username'] ?? null),
            artist: self::stringOrNull($payload['artist'] ?? null),
            name: self::stringOrNull($payload['name'] ?? null),
            description: self::stringOrNull($payload['description'] ?? null),
            link: self::stringOrNull($payload['link'] ?? null),
            thumbnail: self::stringOrNull($payload['thumbnail'] ?? null),
            image: self::stringOrNull($payload['image'] ?? null),
            releaseDate: self::stringOrNull($payload['releaseDate'] ?? null),
            followers: self::intStringOrNull($payload['followers'] ?? null),
            latest: self::arrayOrNull($payload['latest'] ?? null),
            items: self::arrayOrNull($payload['items'] ?? null),
            highlights: self::arrayOrNull($payload['highlights'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'url' => $this->url,
            'channelId' => $this->channelId,
            'apiPath' => $this->apiPath,
            'input' => $this->input,
            'login' => $this->login,
            'username' => $this->username,
            'artist' => $this->artist,
            'name' => $this->name,
            'description' => $this->description,
            'link' => $this->link,
            'thumbnail' => $this->thumbnail,
            'image' => $this->image,
            'releaseDate' => $this->releaseDate,
            'followers' => $this->followers,
            'latest' => $this->latest,
            'items' => $this->items,
            'highlights' => $this->highlights,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intStringOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    /** @return array<mixed>|null */
    private static function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/FeedPayloadTest.php`
Expected: PASS (typed-property + lenient + 8 equivalence cases).

> If any equivalence case fails, a resource reads a key not in the 18 — STOP and add it to the DTO (do not loosen the test). This is the guard that the union is complete.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/FeedPayload.php tests/Unit/Platforms/Payloads/FeedPayloadTest.php
git commit -m "feat(integrations): FeedPayload typed DTO"
```

---

## Task 2: YouTube — `YoutubeFetch` + migrate read paths

**Files:**
- Create: `app/Services/Platforms/Strategies/Fetch/YoutubeFetch.php`
- Test: create `tests/Feature/Platforms/Strategies/FeedFetchParityTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (set `payload(FeedPayload::class)` + attach `YoutubeFetch` on the youtube descriptor)
- Modify: `routes/api/integrations.php` (re-point youtube's `/selection`, `/accounts`, `/accounts/{id}`, `DELETE /` to `GenericPlatformController`)
- Modify: `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` (add a youtube `/selection` pin; the `/accounts` pin already exists)

**Interfaces:**
- Consumes: `YoutubeScraper::fetchRecentVideos(string $handle, int $limit = 15): ?array` (items: `['videoId','name','description','link','date','thumbnail']`), `FetchStrategy`, `FeedPayload`.
- Produces: `final readonly class YoutubeFetch implements FetchStrategy` — on success `[...$payload, latest, name, description, link, thumbnail]` exactly as `PlatformRefresher::youtubePayload` (`PlatformRefresher.php:116-140`).

- [ ] **Step 1: Write the parity test (the proof) — creates the shared parity file**

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;

// gmUser()/gmSeed() are loaded globally by tests/Pest.php:72 (it require_once's
// Feature/Platforms/GoldenMaster/golden_master_helpers.php for the whole suite),
// so no local require is needed — same as IntegrationContractGoldenMasterTest.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('YoutubeFetch produces the same success payload as the refresher (preserves handle + highlights)', function () {
    $videos = [
        ['videoId' => 'v1', 'name' => 'Fresh', 'description' => 'nd', 'link' => 'nl', 'date' => '2026-03-03T00:00:00+00:00', 'thumbnail' => 'nt'],
        ['videoId' => 'v0', 'name' => 'Older', 'description' => 'od', 'link' => 'ol', 'date' => '2026-01-01T00:00:00+00:00', 'thumbnail' => 'ot'],
    ];
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn($videos));

    // Curated highlights + handle MUST survive the refresh (the bug youtubePayload fixes).
    $stored = ['handle' => 'mychannel', 'name' => 'Old', 'description' => 'od', 'link' => 'ol', 'thumbnail' => 'ot', 'highlights' => [['videoId' => 'h1']]];

    $refresherRow = gmSeed(gmUser('gmyt1'), 'youtube', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmyt2'), 'youtube', $stored);
    $result = (new YoutubeFetch(app(YoutubeScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['highlights'])->toBe([['videoId' => 'h1']]); // curated highlights preserved
    expect($result['latest'])->toBe($videos[0]);
});

it('YoutubeFetch throws FetchShapeException when handle is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmyt3'), 'youtube', ['name' => 'no handle']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmyt4'), 'youtube', ['name' => 'no handle']);
    expect(fn () => (new YoutubeFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('YoutubeFetch throws FetchUnavailableException when no videos (refresher status=unavailable)', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([]));

    $row = gmSeed(gmUser('gmyt5'), 'youtube', ['handle' => 'mychannel']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmyt6'), 'youtube', ['handle' => 'mychannel']);
    expect(fn () => (new YoutubeFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/Strategies/FeedFetchParityTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Strategies\Fetch\YoutubeFetch" not found`.

- [ ] **Step 3: Write `YoutubeFetch`** (mirror `youtubePayload`)

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\YoutubeScraper;

// Re-pulls a YouTube channel's latest video by stored handle. Preserves handle +
// curated highlights via the spread; refreshes only the auto-latest tile + the flat
// header. Mirrors PlatformRefresher::youtubePayload EXACTLY.
final readonly class YoutubeFetch implements FetchStrategy
{
    public function __construct(private YoutubeScraper $youtube) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $handle = $payload['handle'] ?? null;
        if (! $handle) {
            throw new FetchShapeException('missing_key: handle');
        }

        $videos = $this->youtube->fetchRecentVideos($handle);
        if (empty($videos)) {
            throw new FetchUnavailableException('youtube_no_videos');
        }
        $latest = $videos[0];

        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
        ];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/Strategies/FeedFetchParityTest.php`
Expected: PASS (3 youtube cases).

- [ ] **Step 5: Attach payload + fetch to the youtube descriptor**

In `app/Providers/PlatformRegistryServiceProvider.php`, on the youtube registration line (`PlatformRegistryServiceProvider.php:82`), append `->payload(...)`, and after the feed `register(...)` block attach the fetch (resolve the scraper from the container):

```php
$r->register(PD::make('youtube')->label('YouTube')->category(Cat::Content)
    ->resource(YoutubeConnectionResource::class)->refreshable()
    ->payload(\App\Services\Platforms\Payloads\FeedPayload::class));
// …later, beside the other fetch attachments:
$r->get('youtube')->fetch(new \App\Services\Platforms\Strategies\Fetch\YoutubeFetch(
    $this->app->make(\App\Services\Platforms\YoutubeScraper::class),
));
```

- [ ] **Step 6: Add the youtube `/selection` golden-master pin (tighten before the route flip)**

In `IntegrationContractGoldenMasterTest.php` (the youtube `/accounts` pin already exists at ~line 75), add:

```php
it('freezes the youtube selection contract', function () {
    $user = gmUser('gmytsel');
    gmSeed($user, 'youtube', [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [], '_leak' => 'x',
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/youtube/selection')->assertOk()->json('selection');

    expect($selection)->toEqual([
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [],
    ]);
});
```

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`
Expected: PASS (against the current `SingleSelectionPlatformController` read path — locks the target before the flip).

- [ ] **Step 7: Re-point youtube's read routes**

In `routes/api/integrations.php`, in the existing youtube group (`integrations.php:105-115`), change the FOUR read routes to `GenericPlatformController` with the platform default; keep `/connect`, `/recent`, `/highlights` on `YoutubeController`:

```php
    Route::prefix("{$base}/youtube")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [YoutubeController::class, 'connect']);
            Route::get('/recent', [YoutubeController::class, 'recent']);
            Route::post('/highlights', [YoutubeController::class, 'highlights']);
            Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', 'youtube');
            Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', 'youtube');
            Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', 'youtube');
            Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', 'youtube');
        });
```

- [ ] **Step 8: Run golden master + youtube feature tests + the net guard**

Run: `php artisan test tests/Feature/Platforms/GoldenMaster tests/Feature/Platforms/PlatformResourceContractTest.php tests/Feature/Platforms/ScraperPlatformsConnectionTest.php`
Expected: PASS. `/connect`, `/recent`, `/highlights` still hit `YoutubeController`; `/selection` + `/accounts` now hit the generic controller with identical JSON; net-completeness still 52.

- [ ] **Step 9: Full suite + commit**

```bash
php artisan test tests/Feature/Platforms tests/Unit/Platforms
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/YoutubeFetch.php \
        tests/Feature/Platforms/Strategies/FeedFetchParityTest.php \
        app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
        tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "refactor(integrations): YoutubeFetch + migrate youtube read paths to GenericPlatformController"
```

---

## Task 3: YouTube Music — `YoutubeMusicFetch` + migrate read paths

**Files:**
- Create: `app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php`
- Test: extend `FeedFetchParityTest.php`
- Modify: provider (payload + fetch on youtube-music), `routes/api/integrations.php` (youtube-music group), golden master (`/selection` pin)

**Interfaces:**
- Consumes: `YoutubeScraper::fetchUploadsFeed(string $channelId, int $limit = 15): ?array` (returns `['title'=>?string, 'videos'=>array]`), `YoutubeMusicController::musicItems(array $videos): array` (public static; items `['itemId','name','thumbnail','link','date','embedUrl']`), `FetchStrategy`, `FeedPayload`.
- Produces: `YoutubeMusicFetch` — on success `[...$payload, name(stripped "- Topic"), thumbnail, latest, items]` exactly as `youtubeMusicPayload` (`PlatformRefresher.php:346-367`), calling `fetchUploadsFeed($channelId, 12)`.

- [ ] **Step 1: Add the youtube-music parity cases to `FeedFetchParityTest.php`**

```php
use App\Http\Controllers\Api\Platforms\YoutubeMusicController;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;

it('YoutubeMusicFetch produces the same success payload as the refresher', function () {
    // Realistic uploads-feed rows: YoutubeMusicController::musicItems() reads
    // $v['videoId'], $v['name'], $v['thumbnail'] (+ link/date) on each video — id-only
    // stubs would make both paths fail identically and prove nothing (and trip PHP 8.2
    // undefined-key warnings).
    $videos = [
        ['videoId' => 'v1', 'name' => 'Track 1', 'thumbnail' => 't1', 'link' => 'l1', 'date' => '2026-03-03T00:00:00+00:00'],
        ['videoId' => 'v2', 'name' => 'Track 2', 'thumbnail' => 't2', 'link' => 'l2', 'date' => '2026-02-02T00:00:00+00:00'],
    ];
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchUploadsFeed')->with('UC123', 12)
        ->andReturn(['title' => 'Artist - Topic', 'videos' => $videos]));

    $stored = ['url' => 'https://music.youtube.com/channel/UC123', 'channelId' => 'UC123', 'name' => 'Old', 'highlights' => [['itemId' => 'h1']]];

    $refresherRow = gmSeed(gmUser('gmym1'), 'youtube-music', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmym2'), 'youtube-music', $stored);
    $result = (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['name'])->toBe('Artist'); // "- Topic" stripped
    expect($result['items'])->toBe(array_slice(YoutubeMusicController::musicItems($videos), 0, 12));
});

it('YoutubeMusicFetch throws FetchShapeException when channelId is missing', function () {
    $row = gmSeed(gmUser('gmym3'), 'youtube-music', ['name' => 'no channel']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmym4'), 'youtube-music', ['name' => 'no channel']);
    expect(fn () => (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('YoutubeMusicFetch throws FetchUnavailableException when the feed is empty', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchUploadsFeed')->andReturn(['title' => 'X', 'videos' => []]));

    $row = gmSeed(gmUser('gmym5'), 'youtube-music', ['channelId' => 'UC123']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmym6'), 'youtube-music', ['channelId' => 'UC123']);
    expect(fn () => (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/Strategies/FeedFetchParityTest.php`
Expected: FAIL — `YoutubeMusicFetch` not found.

- [ ] **Step 3: Write `YoutubeMusicFetch`** (mirror `youtubeMusicPayload`)

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Http\Controllers\Api\Platforms\YoutubeMusicController;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\YoutubeScraper;

// Re-pulls a YouTube Music artist's uploads feed by stored channelId. Strips the
// "- Topic" suffix the auto-channels carry; reshapes the RSS videos into music items.
// Mirrors PlatformRefresher::youtubeMusicPayload EXACTLY (incl. the 12-item fetch +
// slice and the YoutubeMusicController::musicItems reshape).
final readonly class YoutubeMusicFetch implements FetchStrategy
{
    public function __construct(private YoutubeScraper $youtube) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $channelId = $payload['channelId'] ?? null;
        if (! $channelId) {
            throw new FetchShapeException('missing_key: channelId');
        }

        $feed = $this->youtube->fetchUploadsFeed((string) $channelId, 12);
        if ($feed === null || $feed['videos'] === []) {
            throw new FetchUnavailableException('youtube_music_no_releases');
        }
        $items = YoutubeMusicController::musicItems($feed['videos']);

        return [
            ...$payload,
            'name' => $feed['title'] !== null
                ? preg_replace('/\s+-\s+Topic$/', '', $feed['title'])
                : ($payload['name'] ?? null),
            'thumbnail' => $items[0]['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'latest' => $items[0],
            'items' => array_slice($items, 0, 12),
        ];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/Strategies/FeedFetchParityTest.php`
Expected: PASS.

- [ ] **Step 5: Attach payload + fetch; add `/selection` golden-master pin; re-point routes**

Provider — youtube-music registration (`PlatformRegistryServiceProvider.php:83`) gains `->payload(FeedPayload::class)`; attach:
```php
$r->get('youtube-music')->fetch(new \App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch(
    $this->app->make(\App\Services\Platforms\YoutubeScraper::class),
));
```

Golden master — add a youtube-music `/selection` pin (the resource emits `{url,name,thumbnail,link,latest,items,highlights}`):
```php
it('freezes the youtube-music selection contract', function () {
    $user = gmUser('gmymsel');
    gmSeed($user, 'youtube-music', [
        'url' => 'https://music.youtube.com/channel/UC', 'channelId' => 'UC', 'name' => 'Artist',
        'thumbnail' => 't', 'link' => 'https://music.youtube.com/channel/UC',
        'latest' => ['itemId' => 'i1'], 'items' => [['itemId' => 'i1']], 'highlights' => [], '_leak' => 'x',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/youtube-music/selection')->assertOk()->json('selection');

    expect($sel)->toEqual([
        'url' => 'https://music.youtube.com/channel/UC', 'name' => 'Artist', 'thumbnail' => 't',
        'link' => 'https://music.youtube.com/channel/UC', 'latest' => ['itemId' => 'i1'],
        'items' => [['itemId' => 'i1']], 'highlights' => [],
    ]);
    expect($sel)->not->toHaveKey('channelId'); // internal — never emitted
});
```

Routes — youtube-music group (`integrations.php:165-175`): re-point `/selection`, `/accounts`, `/accounts/{id}`, `DELETE /` to `GenericPlatformController` with `->defaults('platform', 'youtube-music')`; keep `/connect`, `/recent`, `/highlights` on `YoutubeMusicController` (same structure as Task 2 Step 7).

- [ ] **Step 6: Run + commit**

```bash
php artisan test tests/Feature/Platforms/GoldenMaster tests/Feature/Platforms/IntegrationsV3ConnectionTest.php tests/Feature/Platforms tests/Unit/Platforms
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php \
        app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
        tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "refactor(integrations): YoutubeMusicFetch + migrate youtube-music read paths"
```

---

## Task 4: Vimeo — `VimeoFetch` + migrate read paths

**Files:** create `VimeoFetch.php`; extend `FeedFetchParityTest.php`; modify provider, routes (vimeo group `integrations.php:151-161`), golden master.

**Interfaces:**
- Consumes: `VimeoApi::fetchVideos(string $apiPath): array`, `VimeoApi::fetchProfile(string $apiPath): ?array` (returns `['name'=>?, 'thumbnail'=>?, …]`), `FetchStrategy`, `FeedPayload`.
- Produces: `VimeoFetch` — `[...$payload, name, thumbnail, latest, items]` exactly as `vimeoPayload` (`PlatformRefresher.php:372-391`); `items = array_slice($videos, 0, 12)`.

- [ ] **Step 1: Add vimeo parity cases**

```php
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\Strategies\Fetch\VimeoFetch;

it('VimeoFetch produces the same success payload as the refresher', function () {
    $videos = array_map(fn ($i) => ['id' => $i], range(1, 15));
    $this->mock(VimeoApi::class, function ($m) use ($videos) {
        $m->shouldReceive('fetchVideos')->andReturn($videos);
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Pat', 'thumbnail' => 'nt']);
    });

    $stored = ['url' => 'https://vimeo.com/pat', 'apiPath' => 'pat', 'name' => 'Old', 'highlights' => [['id' => 'h']]];

    $refresherRow = gmSeed(gmUser('gmvi1'), 'vimeo', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmvi2'), 'vimeo', $stored);
    $result = (new VimeoFetch(app(VimeoApi::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['items'])->toHaveCount(12); // sliced
    expect($result['latest'])->toBe($videos[0]);
});

it('VimeoFetch throws FetchShapeException when apiPath is missing', function () {
    $row = gmSeed(gmUser('gmvi3'), 'vimeo', ['name' => 'no path']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');
    expect(fn () => (new VimeoFetch(app(VimeoApi::class)))->fetch(gmSeed(gmUser('gmvi4'), 'vimeo', ['name' => 'no path'])))
        ->toThrow(FetchShapeException::class);
});

it('VimeoFetch throws FetchUnavailableException when no videos', function () {
    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->andReturn([]));
    $row = gmSeed(gmUser('gmvi5'), 'vimeo', ['apiPath' => 'pat']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');
    expect(fn () => (new VimeoFetch(app(VimeoApi::class)))->fetch(gmSeed(gmUser('gmvi6'), 'vimeo', ['apiPath' => 'pat'])))
        ->toThrow(FetchUnavailableException::class);
});
```

- [ ] **Step 2: Run (fails), then write `VimeoFetch`**

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\VimeoApi;

// Re-pulls a Vimeo profile's latest uploads by stored apiPath. Mirrors
// PlatformRefresher::vimeoPayload EXACTLY (fetchVideos before fetchProfile; the
// latest tile + 12-item slice; profile name/thumbnail fall back to stored values).
final readonly class VimeoFetch implements FetchStrategy
{
    public function __construct(private VimeoApi $vimeo) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $apiPath = $payload['apiPath'] ?? null;
        if (! $apiPath) {
            throw new FetchShapeException('missing_key: apiPath');
        }

        $videos = $this->vimeo->fetchVideos($apiPath);
        if ($videos === []) {
            throw new FetchUnavailableException('vimeo_no_videos');
        }
        $profile = $this->vimeo->fetchProfile($apiPath);

        return [
            ...$payload,
            'name' => $profile['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $profile['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'latest' => $videos[0],
            'items' => array_slice($videos, 0, 12),
        ];
    }
}
```

Run: `php artisan test tests/Feature/Platforms/Strategies/FeedFetchParityTest.php` → PASS.

- [ ] **Step 3: Attach payload + fetch; golden-master `/selection` pin; re-point routes; run; commit**

Provider — vimeo registration (`:84`) gains `->payload(FeedPayload::class)`; attach `VimeoFetch(app(VimeoApi::class))`.
Golden master — add a vimeo `/selection` pin (resource emits `{url,name,thumbnail,link,latest,items,highlights}`; seed an `apiPath` + `_leak` and assert they are absent).
Routes — vimeo group: re-point the four read routes to `GenericPlatformController` (`->defaults('platform','vimeo')`); keep `/connect`, `/recent`, `/highlights`.

```bash
php artisan test tests/Feature/Platforms tests/Unit/Platforms
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/VimeoFetch.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php \
        app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
        tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "refactor(integrations): VimeoFetch + migrate vimeo read paths"
```

---

## Task 5: Bandcamp — `BandcampFetch` + migrate read paths

**Files:** create `BandcampFetch.php`; extend `FeedFetchParityTest.php`; modify provider, routes (bandcamp group `integrations.php:138-148`), golden master.

**Interfaces:**
- Consumes: `BandcampScraper::fetchProfile(string $origin, int $limit = 24): ?array` (returns `['name'=>?, 'items'=>array, 'thumbnail'=>?]`), `BandcampScraper::enrichPrices(array $items, int $cap = 6): array`, `FetchStrategy`, `FeedPayload`.
- Produces: `BandcampFetch` — `[...$payload, artist, latest, name, thumbnail, link]` exactly as `bandcampPayload` (`PlatformRefresher.php:219-241`). Missing `url` → shape error; null profile OR empty items → unavailable. Reads `$payload['url']` (the stored origin).

- [ ] **Step 1: Add bandcamp parity cases** — three cases, following the worked pattern in Tasks 2–4 (run the real `PlatformRefresher` and the strategy against the same container mock; compare payloads + map the two exceptions to the refresher's recorded `last_refresh_status`):
  - **Success:** mock `fetchProfile($url)` → `['name'=>'X','items'=>[['name'=>'Album','thumbnail'=>'t','link'=>'l']],'thumbnail'=>'pt']`. Mock `enrichPrices` to **echo its argument** — but note the strategy (mirroring `bandcampPayload`) calls `enrichPrices([$profile['items'][0]])` (a SINGLE-element array) and reads index `[0]`, so the mock receives `[['name'=>'Album',…]]` and the `$latest` the test asserts on is that element. Assert `$result` `toEqual` the refresher's `fresh()->payload`, with `$result['artist'] === 'X'`, `$result['name'] === 'Album'`, `$result['thumbnail'] === 't'`.
  - **Missing url → shape error:** seed a payload without `url`; assert the refresher records `last_refresh_status === 'error'` and the strategy throws `FetchShapeException`.
  - **Unavailable:** mock `fetchProfile` → `null` (OR → `['name'=>'X','items'=>[],'thumbnail'=>null]`); assert refresher `last_refresh_status === 'unavailable'` and the strategy throws `FetchUnavailableException`.

- [ ] **Step 2: Run (fails), then write `BandcampFetch`**

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls a Bandcamp artist's latest release by stored url (origin). Preserves
// url + curated highlights; refreshes artist name + the auto-latest tile (flat
// fields mirror the connect shape). Mirrors PlatformRefresher::bandcampPayload EXACTLY.
final readonly class BandcampFetch implements FetchStrategy
{
    public function __construct(private BandcampScraper $bandcamp) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }

        $profile = $this->bandcamp->fetchProfile($url);
        if ($profile === null || $profile['items'] === []) {
            throw new FetchUnavailableException('bandcamp_no_releases');
        }
        $latest = $this->bandcamp->enrichPrices([$profile['items'][0]])[0];

        return [
            ...$payload,
            'artist' => $profile['name'] ?? ($payload['artist'] ?? null),
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'] ?? $profile['thumbnail'],
            'link' => $latest['link'],
        ];
    }
}
```

Run parity → PASS.

- [ ] **Step 3: Attach payload + fetch; golden-master `/selection` pin (resource emits `{url,artist,name,thumbnail,link,latest,highlights}`); re-point bandcamp's four read routes to generic (keep `/connect`,`/recent`,`/highlights`); run; commit** `refactor(integrations): BandcampFetch + migrate bandcamp read paths`.

---

## Task 6: Twitch — `TwitchFetch` + migrate read paths (multi-account, via `$migratedReads`)

**Files:** create `TwitchFetch.php`; extend `FeedFetchParityTest.php`; modify provider, routes (move twitch from `$singleSelection`/`$multiAccount` into `$migratedReads`), golden master.

**Interfaces:**
- Consumes: `TwitchScraper::fetchChannel(string $login): ?array` (returns `['name'=>?, 'image'=>?, 'description'=>?]`), `FetchStrategy`, `FeedPayload`.
- Produces: `TwitchFetch` — `[...$payload, name, image, description]` exactly as `twitchPayload` (`PlatformRefresher.php:396-413`). Missing `login` → shape error; null channel → unavailable. **No latest/items/highlights** — Twitch is a card; the embed is built sitepage-side.

- [ ] **Step 1: Add twitch parity cases** (mock `fetchChannel` → `['name'=>'X','image'=>'i','description'=>'bio']`; refresher-vs-strategy parity; missing-login → `FetchShapeException`; null channel → `FetchUnavailableException`).

- [ ] **Step 2: Run (fails), then write `TwitchFetch`**

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\TwitchScraper;

// Re-scrapes a Twitch channel's display name, avatar, and bio by stored login.
// Mirrors PlatformRefresher::twitchPayload EXACTLY (scraped fields fall back to
// stored values; no feed items — the live embed is built sitepage-side).
final readonly class TwitchFetch implements FetchStrategy
{
    public function __construct(private TwitchScraper $twitch) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $login = $payload['login'] ?? null;
        if (! $login) {
            throw new FetchShapeException('missing_key: login');
        }

        $channel = $this->twitch->fetchChannel($login);
        if ($channel === null) {
            throw new FetchUnavailableException('twitch_fetch_failed');
        }

        return [
            ...$payload,
            'name' => $channel['name'] ?? ($payload['name'] ?? null),
            'image' => $channel['image'] ?? ($payload['image'] ?? null),
            'description' => $channel['description'] ?? ($payload['description'] ?? null),
        ];
    }
}
```

Run parity → PASS.

- [ ] **Step 3: Refactor `$migratedReads` to carry a `multi` flag, then move twitch into it**

In `routes/api/integrations.php`: (a) remove `'twitch'` from `$singleSelection` and from `$multiAccount`; (b) change the Plan-3a `$migratedReads` map (a slug→controller map) to a slug→config map and branch on `multi` so single-account platforms get NO `/accounts` routes (preserves net=52):

```php
    // Migrated embed/feed read paths. connect() stays on the thin controller; the
    // read paths are served by the registry-driven GenericPlatformController via the
    // platform route default. `multi` gates the extra /accounts routes — single
    // platforms must NOT gain them (keeps the net-completeness count at 52).
    $migratedReads = [
        'spotify' => ['controller' => SpotifyController::class, 'multi' => true],
        'soundcloud' => ['controller' => SoundcloudController::class, 'multi' => true],
        'deezer' => ['controller' => DeezerController::class, 'multi' => true],
        'twitch' => ['controller' => TwitchController::class, 'multi' => true],
    ];
    foreach ($migratedReads as $slug => $cfg) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($cfg, $slug) {
                Route::post('/connect', [$cfg['controller'], 'connect']);
                Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $slug);
                Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', $slug);
                if ($cfg['multi']) {
                    Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', $slug);
                    Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                        ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', $slug);
                }
            });
    }
```

(`TwitchController` is already imported.) The spotify/soundcloud/deezer URIs are unchanged by the refactor.

- [ ] **Step 4: Provider + golden master + run + commit**

Provider — twitch registration (`:85`) gains `->payload(FeedPayload::class)`; attach `TwitchFetch(app(TwitchScraper::class))`.
Golden master — add a twitch `/selection` pin (`{url,login,name,image,description}`) and a twitch `/accounts` pin (multi). Confirm net guard still 52.

```bash
php artisan test tests/Feature/Platforms tests/Unit/Platforms
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/TwitchFetch.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php \
        app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
        tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "refactor(integrations): TwitchFetch + migrate twitch read paths (multi-account)"
```

---

## Task 7: Pinterest — `PinterestFetch` + migrate read paths (single-account, via `$migratedReads`)

**Files:** create `PinterestFetch.php`; extend `FeedFetchParityTest.php`; modify provider, routes (move pinterest into `$migratedReads` with `multi=false`), golden master.

**Interfaces:**
- Consumes: `PinterestScraper::fetchProfile(string $username): ?array` (returns `['name'=>?, 'image'=>?, 'followers'=>?]`), `PinterestScraper::fetchPins(string $username, int $limit = 12): array`, `FetchStrategy`, `FeedPayload`.
- Produces: `PinterestFetch` — `[...$payload, name, image, followers, latest, items]` exactly as `pinterestPayload` (`PlatformRefresher.php:418-438`). Missing `username` → shape error; null profile → unavailable. `latest`/`items` fall back to stored when pins are empty.

- [ ] **Step 1: Add pinterest parity cases** (mock `fetchProfile` + `fetchPins`; refresher-vs-strategy parity; missing-username → `FetchShapeException`; null profile → `FetchUnavailableException`).

- [ ] **Step 2: Run (fails), then write `PinterestFetch`**

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\PinterestScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls a Pinterest profile (name/avatar/followers) + latest pins by stored
// username. Mirrors PlatformRefresher::pinterestPayload EXACTLY (pins fall back to
// the stored latest/items when the RSS is empty).
final readonly class PinterestFetch implements FetchStrategy
{
    public function __construct(private PinterestScraper $pinterest) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $username = $payload['username'] ?? null;
        if (! $username) {
            throw new FetchShapeException('missing_key: username');
        }

        $profile = $this->pinterest->fetchProfile($username);
        if ($profile === null) {
            throw new FetchUnavailableException('pinterest_fetch_failed');
        }
        $pins = $this->pinterest->fetchPins($username);

        return [
            ...$payload,
            'name' => $profile['name'] ?? ($payload['name'] ?? null),
            'image' => $profile['image'] ?? ($payload['image'] ?? null),
            'followers' => $profile['followers'] ?? ($payload['followers'] ?? null),
            'latest' => $pins[0] ?? ($payload['latest'] ?? null),
            'items' => $pins !== [] ? $pins : ($payload['items'] ?? []),
        ];
    }
}
```

Run parity → PASS.

- [ ] **Step 3: Move pinterest into `$migratedReads` (single)**

In `routes/api/integrations.php`, remove `'pinterest'` from `$singleSelection` and add to `$migratedReads`:
```php
        'pinterest' => ['controller' => PinterestController::class, 'multi' => false],
```
The `multi => false` branch registers only `/connect` + `/selection` + `DELETE /` — exactly pinterest's current three routes (it had no `/accounts`), so the net-completeness count is unchanged. (`PinterestController` is already imported.)

- [ ] **Step 4: Provider + golden master + run + commit**

Provider — pinterest registration (`:86`) gains `->payload(FeedPayload::class)`; attach `PinterestFetch(app(PinterestScraper::class))`.
Golden master — add a pinterest `/selection` pin (`{url,username,name,image,followers,latest,items}`).

```bash
php artisan test tests/Feature/Platforms tests/Unit/Platforms
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/PinterestFetch.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php \
        app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
        tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "refactor(integrations): PinterestFetch + migrate pinterest read paths (single-account)"
```

---

## Task 8: Apple — `AppleMusicFetch` + `ApplePodcastFetch` + migrate the dual read paths

**Files:** create `AppleMusicFetch.php` + `ApplePodcastFetch.php`; extend `FeedFetchParityTest.php`; modify provider (apple-music + apple-podcast), routes (apple group `integrations.php:117-135`), golden master.

> **Apple is dual + bespoke.** `AppleController` extends `ApiController` (not `SingleSelectionPlatformController`) and serves two registry platforms (`apple-music`, `apple-podcast`) under one `/apple` route group. Per scope, it KEEPS `connectMusic`/`connectPodcast`/`musicRecent`/`podcastRecent`/`musicHighlights`/`podcastHighlights` and the three `forget*` DELETEs (`DELETE /` clears BOTH sub-platforms — apple-specific, must stay). Only the GET selection/accounts (and accounts/{id} DELETE) read routes migrate, each carrying its sub-platform slug as the route default.

**Interfaces:**
- Consumes: `AppleSearch::fetchAlbums(string $input, int $limit = 15): ?array`, `AppleSearch::fetchEpisodes(string $input, int $limit = 15): ?array`, `FetchStrategy`, `FeedPayload`.
- Produces:
  - `AppleMusicFetch` — `[...$payload, latest, name, thumbnail, releaseDate, link]` exactly as `appleMusicPayload` (`PlatformRefresher.php:271-292`).
  - `ApplePodcastFetch` — `[...$payload, latest, name, thumbnail, description, link]` exactly as `applePodcastPayload` (`PlatformRefresher.php:297-317`).
  - Both: missing `input` → shape error; empty albums/episodes → unavailable.

- [ ] **Step 1: Add apple-music + apple-podcast parity cases** (mock `AppleSearch::fetchAlbums`/`fetchEpisodes`; refresher-vs-strategy parity for each platform key; missing-input → `FetchShapeException`; empty result → `FetchUnavailableException`).

- [ ] **Step 2: Run (fails), then write both strategies**

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls an Apple Music artist's latest album by stored input. Preserves input +
// curated highlights; refreshes the "most recent" tile. Mirrors
// PlatformRefresher::appleMusicPayload EXACTLY.
final readonly class AppleMusicFetch implements FetchStrategy
{
    public function __construct(private AppleSearch $apple) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $input = $payload['input'] ?? null;
        if (! $input) {
            throw new FetchShapeException('missing_key: input');
        }

        $albums = $this->apple->fetchAlbums($input);
        if (empty($albums)) {
            throw new FetchUnavailableException('apple_music_no_albums');
        }
        $latest = $albums[0];

        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
        ];
    }
}
```

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls an Apple Podcasts show's latest episode by stored input. Mirrors
// PlatformRefresher::applePodcastPayload EXACTLY (header exposes `description`
// where Apple Music exposes `releaseDate` only).
final readonly class ApplePodcastFetch implements FetchStrategy
{
    public function __construct(private AppleSearch $apple) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $input = $payload['input'] ?? null;
        if (! $input) {
            throw new FetchShapeException('missing_key: input');
        }

        $episodes = $this->apple->fetchEpisodes($input);
        if (empty($episodes)) {
            throw new FetchUnavailableException('apple_podcast_no_episodes');
        }
        $latest = $episodes[0];

        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'description' => $latest['description'],
            'link' => $latest['link'],
        ];
    }
}
```

Run parity → PASS.

- [ ] **Step 3: Attach payload + fetch on both apple descriptors**

Provider — apple-music registration (`:88`) gains `->payload(FeedPayload::class)` + `$r->get('apple-music')->fetch(new AppleMusicFetch(app(AppleSearch::class)))`; apple-podcast (`:89`) gains `->payload(FeedPayload::class)` + `ApplePodcastFetch(app(AppleSearch::class))`.

- [ ] **Step 4: Re-point apple's GET read routes** (keep connect/recent/highlights + the forget DELETEs)

In the `/apple` group (`integrations.php:117-135`), re-point the GET selection/accounts (and the accounts/{id} DELETE) for each sub-platform:
```php
            // music reads → generic (platform=apple-music)
            Route::get('/music/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', 'apple-music');
            Route::get('/music/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', 'apple-music');
            Route::delete('/music/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', 'apple-music');
            // podcast reads → generic (platform=apple-podcast)
            Route::get('/podcast/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', 'apple-podcast');
            Route::get('/podcast/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', 'apple-podcast');
            Route::delete('/podcast/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', 'apple-podcast');
```
Leave `POST /music/connect`, `/music/recent`, `/music/highlights`, the podcast equivalents, and `DELETE /music`, `DELETE /podcast`, `DELETE /` on `AppleController` (the `DELETE /` clears both sub-platforms — apple-specific).

- [ ] **Step 5: Golden master + run + commit**

Golden master — add apple-music + apple-podcast `/music/selection` and `/podcast/selection` pins (music: `{input,name,thumbnail,releaseDate,link,latest,highlights}`; podcast adds `description`). Confirm net guard still 52 (these URIs already existed; the apple `/music/recent`/`/podcast/recent` picker routes are excluded from the net by the existing `/recent` reject filter).

```bash
php artisan test tests/Feature/Platforms tests/Unit/Platforms
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php app/Services/Platforms/Strategies/Fetch/ApplePodcastFetch.php \
        tests/Feature/Platforms/Strategies/FeedFetchParityTest.php app/Providers/PlatformRegistryServiceProvider.php \
        routes/api/integrations.php tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git commit -m "refactor(integrations): Apple music/podcast fetch strategies + migrate dual read paths"
```

---

## Task 9: Google Business — `GoogleBusinessFetch` only (read-path deferred to Plan 5)

**Files:** create `GoogleBusinessFetch.php`; extend `FeedFetchParityTest.php`; modify provider (attach fetch to google-business — but NOT `->payload(FeedPayload::class)`); add a registry assertion.

> **Read the "Why GoogleBusiness…" section above before starting.** This task builds + parity-tests + attaches the fetch strategy ONLY. google-business's read path stays on `SingleSelectionPlatformController` and its descriptor does NOT get `FeedPayload` (its conditional-emission resource is incompatible). Its payload DTO + read-path migration are Plan 5.

**Interfaces:**
- Consumes: `GoogleBusinessService::fetchPlaceDetails(string $placeId): ?array`, `Illuminate\Support\Carbon`, `FetchStrategy`.
- Produces: `GoogleBusinessFetch` — on success `[...$payload, ...$details]` exactly as `googleBusinessPayload` (`PlatformRefresher.php:474-500`), INCLUDING the freshness short-circuit (returns the unchanged payload when `detailsFetchedAt` < 6 days) and the **asymmetry**: missing `placeId` is `status='unavailable'` (legacy link-paste rows legitimately lack one) → `FetchUnavailableException`, NOT `FetchShapeException`. Failed `fetchPlaceDetails` → `FetchUnavailableException`.

- [ ] **Step 1: Add google-business parity cases** — three behaviours to pin against the refresher:
  1. **Stale → re-fetched:** seed `{placeId:'p1', detailsFetchedAt: <8 days ago>, …}`, mock `fetchPlaceDetails('p1')` → `['rating'=>4.5,'reviewCount'=>10]`; assert strategy result `toEqual` the refresher's payload (`[...$payload, ...$details]`).
  2. **Fresh → short-circuit:** seed `detailsFetchedAt: now()->subDay()`; mock so `fetchPlaceDetails` is NEVER called (`shouldNotReceive('fetchPlaceDetails')`); assert strategy returns the payload unchanged AND the refresher recorded `status='ok'` with the unchanged payload.
  3. **Missing placeId → unavailable (NOT error):** seed `{url:'…', name:'…'}` (no placeId); assert refresher `last_refresh_status === 'unavailable'` and the strategy throws `FetchUnavailableException`.

  Use `now()->subDays(8)->toIso8601String()` for the stale stamp and `now()->subDay()->toIso8601String()` for fresh (the helper seeds via `gmSeed`; `now()` is available in tests).

- [ ] **Step 2: Run (fails), then write `GoogleBusinessFetch`** (mirror `googleBusinessPayload`)

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Illuminate\Support\Carbon;

// Re-pulls a Google Business Place Details snapshot by stored placeId. The cron is
// daily but the snapshot only needs ~weekly re-pulls (Google billing + ToS caching),
// so a detailsFetchedAt < 6 days short-circuits with no API call. Mirrors
// PlatformRefresher::googleBusinessPayload EXACTLY — note the asymmetry: a MISSING
// placeId is 'unavailable' (legacy link-paste rows lack one), not a shape error.
final readonly class GoogleBusinessFetch implements FetchStrategy
{
    public function __construct(private GoogleBusinessService $googleBusiness) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $placeId = $payload['placeId'] ?? null;
        if (! $placeId) {
            // Legacy link-paste connections legitimately lack a placeId — transient,
            // not a data-integrity error (refresher status='unavailable').
            throw new FetchUnavailableException('missing_place_id');
        }

        try {
            $fresh = isset($payload['detailsFetchedAt'])
                && Carbon::parse($payload['detailsFetchedAt'])->gt(now()->subDays(6));
        } catch (\Throwable) {
            $fresh = false;
        }
        if ($fresh) {
            return $payload;
        }

        $details = $this->googleBusiness->fetchPlaceDetails((string) $placeId);
        if ($details === null) {
            throw new FetchUnavailableException('google_details_fetch_failed');
        }

        return [...$payload, ...$details];
    }
}
```

Run parity → PASS.

- [ ] **Step 3: Attach the fetch strategy (NO payload class) + assert + commit**

Provider — attach ONLY the fetch to the google-business descriptor (leave its registration line `:90` otherwise unchanged — no `->payload(...)`):
```php
$r->get('google-business')->fetch(new \App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch(
    $this->app->make(\App\Services\Platforms\GoogleBusinessService::class),
));
```

Registry assertion — append to `RegistryCoverageTest.php`:
```php
it('attaches GoogleBusinessFetch but defers its payload/read-path to Plan 5', function () {
    $registry = app(\App\Services\Platforms\Registry\PlatformRegistry::class);
    $d = $registry->get('google-business');

    expect($d->fetchStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch::class);
    // Intentionally NOT FeedPayload — its resource emits a variable key set (see plan).
    expect($d->payloadClass())->toBeNull();
});
```

```bash
php artisan test tests/Feature/Platforms tests/Unit/Platforms
php artisan pint --dirty
git add app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php \
        app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "feat(integrations): GoogleBusinessFetch strategy (read-path deferred to Plan 5)"
```

---

## Task 10: Feed archetype verification

**Files:** none (verification only).

- [ ] **Step 1: Full platforms suite**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: GREEN.

- [ ] **Step 2: Net-completeness invariant + registry coverage**

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php tests/Feature/Platforms/Registry`
Expected: GREEN; the net guard reports 52; `RegistryCoverageTest` still enumerates the same 36 platform keys and the same `PlatformRefresher::REFRESHABLE` set (this plan changed neither).

- [ ] **Step 3: Full suite + Pint**

Run:
```bash
php artisan test
php artisan pint --dirty
```
Expected: GREEN; Pint clean.

- [ ] **Step 4: Final confirmation checklist (no code)**

Confirm by inspection:
- `FeedPayload` is resource-output-equivalent for all 8 feed platforms (the equivalence dataset is green); `channelId`/`apiPath` are carried but emitted by no resource.
- Each of youtube/youtube-music/vimeo/bandcamp/twitch/pinterest/apple-music/apple-podcast has a parity-tested `FetchStrategy` attached to its descriptor, and `->payload(FeedPayload::class)` set.
- youtube/youtube-music/vimeo/bandcamp/twitch/pinterest/apple `/selection` + `/accounts` resolve through `GenericPlatformController`; `/connect` + `/recent` + `/highlights` (+ apple `forget*`) stay on the thin controllers.
- google-business has `GoogleBusinessFetch` attached, `payloadClass()` is null, and its read path is UNCHANGED (still `SingleSelectionPlatformController`).
- `PlatformRefresher` is UNCHANGED (git diff shows no edits).
- Net-completeness count is still 52.

This plan does NOT wire the fetch strategies into the refresher — that is Plan 6.

---

## Deferred (NOT in this plan)

- **google-business payload DTO + read-path migration + `/synced` + `/synced/apply` + auto-sync** → Plan 5 (its conditional-emission resource + auto-sync seeding are special behaviour). The fetch strategy is built here; nothing else for google-business.
- **Picker / shop archetypes** (fresha, square, opentable, resdiary, nowbookit, shop) + `SelectionPayload`/`ShopPayload` → Plan 4.
- **Bespoke / specials** (Instagram async, Fresha multi-step, Shop multi-brand, events smart-detect, skool/strava link stragglers) → Plan 4/5.
- **eventbrite + humanitix.** The spec §7 table lists them in the scraped/API-feed row, but they do NOT use the flat `FeedPayload` — they store an organiser-accounts-plus-events structure built by `EventsPayload` (`accountPayload`/`standalonePayload`) and refresh via `eventbritePayload`/`humanitixPayload`. They are events-archetype, handled with the events smart-detect facade → Plan 4/5. They are deliberately excluded from `FeedPayload` and from this plan's fetch strategies.
- **`PlatformRefresher` `match()` → registry iteration** + wiring `OnDemandRefresh`/`ScheduledRefresh` to `try/catch` the `FetchShapeException`/`FetchUnavailableException` mapping, the `ProviderDetector` rewrite, and the `DROP CONSTRAINT` migration → Plan 6.
- **Adopting `FeedPayload` inside the thin controllers' `connect()`/`recent`/`highlights`** (read-path only this plan).
- **Removing the now-route-bypassed read methods from `SingleSelectionPlatformController`** (still used by skool/strava/opentable/resdiary/nowbookit/google-business) → after Plan 4.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-28-platform-integrations-feed.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
