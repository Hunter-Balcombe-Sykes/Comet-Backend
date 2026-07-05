# Platform Connect Convergence (FOUND-24 full) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Converge every non-bespoke platform onto the descriptor-driven connect path (ConnectStrategy + HighlightsStrategy), delete the 13 thin controllers + `SingleSelectionPlatformController` + 4 duplicated route groups, and lock the single-path architecture in with a guard test.

**Architecture:** The registry spine (PlatformRegistry/PlatformDescriptor, FOUND-19/21 + registry redesign Plans 1–6) already serves reads (`selection`/`accounts`/`forget`) for all these platforms via `GenericPlatformController`, and already serves `connect` for the 6 link-only socials via a thin `ConnectStrategy`. This plan (1) enriches the ConnectStrategy contract with two-stage error semantics (`ConnectResult`), (2) teaches `GenericPlatformController::connect` the multi-account path, (3) migrates 13 platforms' `connect()` bodies into strategy classes, (4) adds an optional `HighlightsStrategy` so the route loop emits `/recent` + `/highlights` too, then (5) deletes the superseded controllers/routes/requests and adds an architecture guard test. Zero DB changes. Response payloads must stay byte-identical — the golden-master suite (57 pinned read-routes + exact shapes) is the safety net.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4. No migrations, no new dependencies.

**Review status:** Independently reviewed by a separate Opus agent 2026-07-05 (adversarial premise/parity/test-net/boot-safety/ordering check). All 13 verbatim code moves verified word-for-word against source. 4 defects found and folded back into this document (Task 1 Step 9 test target, Task 10 comment cleanup, RegistryRouteShapeTest scope corrections, YoutubeMusicFetch import swap). Verdict: ship.

## Global Constraints

- **Frozen API contract.** Every response shape, 422/404 message, and route URI is pinned by `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` (57 read routes, exact URI list + exact key sets) and the per-platform contract tests. Reproduce messages/shapes **verbatim** — copy from the controller being deleted, never paraphrase.
- **No Laravel migrations, no SQL.** This plan touches zero schema.
- **Lazy strategy factories are LOAD-BEARING.** The registry singleton is built at boot (routes iterate it). Any strategy wrapping a scraper/API client MUST be registered as a `fn () => new XStrategy(app(Scraper::class))` Closure, never a ready instance — otherwise the real client is baked in before tests can bind mocks (the SEC-1 timing gotcha; see `PlatformDescriptor::fetch()` docblock).
- **Descriptor `hasHighlights()` must NOT resolve the factory** — the route loop calls it at boot.
- **Store payloads verbatim.** Migrated platforms store the strategy's selection array exactly as the deleted controller did. Only the link-only archetype keeps its existing `LinkPayload` round-trip (gate: `payloadClass() === LinkPayload::class`).
- **Pint targeted, not repo-wide:** `vendor/bin/pint <changed files only>` before each commit (repo-wide pint churns the style baseline).
- **Full suite before push:** `composer test` at every task boundary. A filtered green is a false signal (short-ref/namespace gotcha). Never run two test suites concurrently.
- **Tests are SQLite** — no Postgres constraints exercised. Irrelevant here (no writes change shape), but do not add tests that rely on DB CHECK behavior.
- Branch: `feat/platform-connect-convergence-2026-07-05` off `origin/development` (fetch + pull first; shared repo).

## File Structure (end state)

```
app/Services/Platforms/Strategies/
  Contracts/ConnectStrategy.php          — MODIFIED: normalize() → resolve(): ConnectResult
  Contracts/ConnectResult.php            — NEW: ok(selection, accountKey?) | fail(message?, status)
  Contracts/HighlightsStrategy.php       — NEW: recent/highlights picker contract
  Connect/UrlConnect.php                 — MODIFIED: implements resolve()
  Connect/{Spotify,Soundcloud,Deezer,Twitch,Pinterest,Strava,NowBookit,OpenTable,ResDiary,
           Youtube,YoutubeMusic,Vimeo,Bandcamp}Connect.php   — NEW (13)
  Highlights/{Youtube,YoutubeMusic,Vimeo,Bandcamp}Highlights.php — NEW (4)
app/Services/Platforms/Concerns/RefreshesLatestTile.php  — MOVED from Http/Controllers/Api/Platforms/Concerns
app/Services/Platforms/YoutubeMusicItems.php             — NEW: musicItems() relocated
app/Http/Controllers/Api/Platforms/
  GenericPlatformController.php          — MODIFIED: multi-account connect + recent + highlights
  OpenTableController.php                — MODIFIED: shrinks to suggestion() only
  GoogleBusinessController.php           — MODIFIED: absorbs base-class methods
  SingleSelectionPlatformController.php  — DELETED (last)
  {Spotify,Soundcloud,Deezer,Twitch,Pinterest,Strava,NowBookit,ResDiary,
   Youtube,YoutubeMusic,Vimeo,Bandcamp}Controller.php     — DELETED (12)
app/Http/Requests/Platforms/
  PlatformHighlightsRequest.php          — NEW (descriptor-driven, like PlatformConnectRequest)
  Save{Youtube,YoutubeMusic,Vimeo,Bandcamp}HighlightsRequest.php — DELETED (4; Apple's two stay)
app/Providers/PlatformRegistryServiceProvider.php — MODIFIED: strategy registrations
routes/api/platforms.php                 — MODIFIED: 4 groups deleted, loop emits recent/highlights
tests/Feature/Architecture/PlatformControllerConvergenceTest.php — NEW guard
```

Bespoke survivors (deliberate, out of scope): Apple (dual music/podcast), GoogleBusiness (Places sync), Instagram (async), Skool (needs payload-DTO work first), Fresha/Square/Shop/Events/Eventbrite/Humanitix/CustomLinks/Booking/Reservations/OnlineOrdering/Menu/Refresh/Meta.

---

### Task 1: ConnectResult + enriched ConnectStrategy contract + generic multi-account connect

**Files:**
- Create: `app/Services/Platforms/Strategies/Contracts/ConnectResult.php`
- Modify: `app/Services/Platforms/Strategies/Contracts/ConnectStrategy.php`
- Modify: `app/Services/Platforms/Strategies/Connect/UrlConnect.php`
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php` (lazy connect factory)
- Modify: `app/Http/Controllers/Api/Platforms/GenericPlatformController.php` (connect rewrite)
- Modify: `routes/api/platforms.php:300-302` (connect-controller fallback)
- Test: `tests/Feature/Platforms/GenericLinkControllerTest.php` (existing — must stay green unchanged)
- Test: `tests/Feature/Platforms/RemoveAccountTest.php` (NEW — closes the pre-existing coverage gap)

**Interfaces:**
- Consumes: existing `PlatformDescriptor` fluent API, `ManagesIntegrationConnection` trait (`writeAccountConnection`, `maxAccounts`, `preserveHighlights`, `writeConnection`).
- Produces: `ConnectResult::ok(array $selection, ?string $accountKey = null)`, `ConnectResult::fail(?string $message = null, int $status = 422)`, `ConnectResult->failed(): bool`, properties `selection/accountKey/error/status`; `ConnectStrategy::resolve(string $input): ConnectResult`; `PlatformDescriptor::connect(ConnectStrategy|Closure $strategy, string $errorMessage)`; `PlatformDescriptor::hasHighlights(): bool` (stub returning false until Task 6). Later tasks' strategies implement `resolve()` and are registered as Closures.

- [ ] **Step 1: Write the failing test for the removeAccount coverage gap** (behavior exists today, no HTTP test does — this is the regression net every later task leans on)

Create `tests/Feature/Platforms/RemoveAccountTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\TwitchScraper;

// DELETE /api/platforms/{platform}/accounts/{id} — the one multi-account
// endpoint with no direct HTTP coverage before the connect convergence.
// Twitch reads are already registry-driven, so this pins GenericPlatformController.

it('removes one twitch account and returns the remaining list', function () {
    $user = User::factory()->create();

    foreach ([['a', 'AlphaChan'], ['b', 'BetaChan']] as [$suffix, $name]) {
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'twitch',
            'resource_id' => "acct-{$suffix}0000000000000000",
            'payload' => ['url' => "https://www.twitch.tv/{$suffix}", 'login' => $suffix, 'name' => $name, 'image' => null, 'description' => null],
            'is_active' => true,
        ]);
    }

    $res = $this->actingAsUser($user)->deleteJson('/api/platforms/twitch/accounts/acct-a0000000000000000');

    $res->assertOk();
    expect(collect($res->json('data.accounts'))->pluck('id')->all())->toBe(['acct-b0000000000000000']);
});

it('404s when removing an account the user does not own', function () {
    $user = User::factory()->create();

    $res = $this->actingAsUser($user)->deleteJson('/api/platforms/twitch/accounts/acct-missing000000000');

    $res->assertNotFound();
});
```

Match the auth helper to what `tests/Feature/Platforms/IntegrationsV3ConnectionTest.php` actually uses (`actingAsUser`, `authHeaders`, or similar) and copy the `IntegrationConnection::create` field set from `tests/Feature/Platforms/GoldenMaster/golden_master_helpers.php` `gmSeed()` — reuse the helper if it is autoloaded for all Feature tests.

- [ ] **Step 2: Run it — expect PASS (it pins current behavior)**

Run: `./vendor/bin/pest tests/Feature/Platforms/RemoveAccountTest.php`
Expected: PASS. If it fails, fix the test setup (auth helper / seed shape), not the production code.

- [ ] **Step 3: Create ConnectResult**

`app/Services/Platforms/Strategies/Contracts/ConnectResult.php`:

```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

// Outcome of a platform's connect resolution. Two-stage failures (parse vs
// fetch) carry their own message + HTTP status; a null message falls back to
// the descriptor's connectErrorMessage (the parse-fail wording each platform
// froze into its API contract). accountKey is the canonical per-account
// identity for multi-account platforms whose key is not derivable from the
// selection's handle/input/url/link chain (e.g. Vimeo's apiPath).
final readonly class ConnectResult
{
    private function __construct(
        public ?array $selection,
        public ?string $accountKey,
        public ?string $error,
        public int $status,
    ) {}

    public static function ok(array $selection, ?string $accountKey = null): self
    {
        return new self($selection, $accountKey, null, 200);
    }

    public static function fail(?string $message = null, int $status = 422): self
    {
        return new self(null, null, $message, $status);
    }

    public function failed(): bool
    {
        return $this->selection === null;
    }
}
```

- [ ] **Step 4: Change the ConnectStrategy contract**

`app/Services/Platforms/Strategies/Contracts/ConnectStrategy.php` — replace the interface body:

```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

// How a platform turns raw user input (URL / handle) into the canonical stored
// selection array — including any upstream fetch. fail() with no message uses
// the descriptor's connectErrorMessage; fetch-stage failures carry their own
// message (and 404 where the platform's frozen contract says so).
interface ConnectStrategy
{
    public function resolve(string $input): ConnectResult;
}
```

(`ApiKeyConnect` / `OAuthConnect` marker sub-interfaces extend this and have no implementations — they inherit the new signature untouched.)

- [ ] **Step 5: Update UrlConnect**

`app/Services/Platforms/Strategies/Connect/UrlConnect.php` — replace `normalize()`:

```php
    public function resolve(string $input): ConnectResult
    {
        $selection = ($this->normalizer)($input);

        return $selection === null ? ConnectResult::fail() : ConnectResult::ok($selection);
    }
```

Add `use App\Services\Platforms\Strategies\Contracts\ConnectResult;`.

- [ ] **Step 6: Lazy connect factory + hasHighlights stub on the descriptor**

`app/Services/Platforms/Registry/PlatformDescriptor.php` — replace the `$connectStrategy` property and `connect()`/`connectStrategy()` methods (keep the docblock, adjusting the first line):

```php
    /** @var (Closure(): ConnectStrategy)|null Lazily builds the connect strategy (same rationale as fetch()). */
    private ?Closure $connectFactory = null;
```

```php
    public function connect(ConnectStrategy|Closure $strategy, string $errorMessage): self
    {
        $this->connectFactory = $strategy instanceof Closure ? $strategy : fn () => $strategy;
        $this->connectErrorMessage = $errorMessage;

        return $this;
    }

    public function connectStrategy(): ?ConnectStrategy
    {
        return $this->connectFactory !== null ? ($this->connectFactory)() : null;
    }

    /** Boot-safe highlights probe — real factory lands with HighlightsStrategy (Task 6). */
    public function hasHighlights(): bool
    {
        return false;
    }
```

- [ ] **Step 7: Rewrite GenericPlatformController::connect for result semantics + multi-account**

`app/Http/Controllers/Api/Platforms/GenericPlatformController.php` — replace `connect()` and add the private key helper:

```php
    // POST /api/platforms/{platform}/connect — resolve the input via the
    // descriptor's connect strategy (parse + any upstream fetch), store the
    // canonical selection, echo it. Multi-account platforms add an account row
    // (capped, shop-style); single-selection platforms upsert the one row.
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $descriptor = $this->descriptor();

        // Capability checkpoint (spec §9) — true for everyone today; the gate
        // exists so future paid-tier/account-type rules are a per-descriptor flag.
        $this->authorizeForUser($user, 'connect', [new IntegrationConnection(['user_id' => $user->id]), $descriptor]);

        $strategy = $descriptor->connectStrategy();
        abort_if($strategy === null, 404);

        $result = $strategy->resolve($request->validated()[$descriptor->connectField()]);
        if ($result->failed()) {
            return $this->error($result->error ?? $descriptor->connectErrorMessage() ?? 'Enter a valid link.', $result->status);
        }

        $selection = $result->selection;

        // Link-only platforms keep their existing LinkPayload round-trip; every
        // other archetype stores the strategy's selection verbatim, exactly as
        // the per-platform controllers did.
        if ($descriptor->payloadClass() === LinkPayload::class) {
            $selection = LinkPayload::fromArray($selection)->toArray();
        }

        $resourceClass = $descriptor->resourceClass();

        if ($descriptor->multiAccount()) {
            $key = $result->accountKey ?? $this->defaultAccountKey($selection);
            if ($key !== null) {
                if ($descriptor->hasHighlights()) {
                    // Re-adding an already-connected account keeps its curated highlights.
                    $selection['highlights'] = $this->preserveHighlights($user, $key);
                }
                $row = $this->writeAccountConnection($user, $key, $selection);
                if ($row === null) {
                    return $this->error('You can connect up to '.$this->maxAccounts().' accounts.', 422);
                }

                return $this->success(['id' => $row->resource_id, ...(new $resourceClass($selection))->resolve()]);
            }
        }

        $this->writeConnection($user, $selection);

        return $this->success((new $resourceClass($selection))->resolve());
    }

    /** Canonical per-account key of a freshly-built selection (mirrors the
     *  deleted single-selection base's default — dedupe + id source). */
    private function defaultAccountKey(array $selection): ?string
    {
        $key = $selection['handle'] ?? $selection['input'] ?? $selection['url'] ?? $selection['link'] ?? null;

        return is_string($key) && trim($key) !== '' ? $key : null;
    }
```

Behavioral notes (verify while implementing, do not skip):
- Link-only socials are `multiAccount() === false`, so they take the single-selection path exactly as before.
- The `'connect'` policy ability (`IntegrationConnectionPolicy::connect`, `app/Policies/IntegrationConnectionPolicy.php:50`) runs `denyIfPendingDeletion` + `availableFor` (always true) + `ownerMatches` — all of which the bespoke path already enforced via the `EnforcePendingDeletionReadOnly` middleware + `writeConnection`'s create/update gates, so migrated platforms see no new denial.

- [ ] **Step 8: Route-loop connect fallback**

`routes/api/platforms.php` — in the registry loop, replace:

```php
                $connectController = $shape === PlatformRouteShape::LinkOnly
                    ? GenericPlatformController::class
                    : $descriptor->connectController();
```

with:

```php
                // Null connectController = fully registry-driven (link-only, and
                // every platform migrated onto a ConnectStrategy).
                $connectController = $descriptor->connectController() ?? GenericPlatformController::class;
```

(LinkOnly descriptors never set a connectController, so behavior is identical today.)

- [ ] **Step 9: Fix the descriptor unit test that calls the old signature**

`tests/Unit/Platforms/Registry/PlatformDescriptorTest.php:61-75` calls `$d->connectStrategy()->normalize('good')` / `->normalize('bad')` — these fatal after the contract change. Update to the new contract: `resolve('good')->selection` equals the expected array, `resolve('bad')->failed()` is true. (The `->connect(new UrlConnect(...), ...)` instance-style call in that test still works under the widened `ConnectStrategy|Closure` signature.) `tests/Unit/Platforms/Registry/StrategyContractsTest.php` needs NO edit — it only asserts `interface_exists()` + the no-OAuth-implementors seam.

- [ ] **Step 10: Run the full suite**

Run: `composer test`
Expected: PASS — link-only connects (`GenericLinkControllerTest`) byte-identical, golden master green, new RemoveAccountTest green.

- [ ] **Step 11: Commit**

```bash
git add app/Services/Platforms/Strategies app/Services/Platforms/Registry/PlatformDescriptor.php \
  app/Http/Controllers/Api/Platforms/GenericPlatformController.php routes/api/platforms.php \
  tests/Feature/Platforms/RemoveAccountTest.php tests/Unit/Platforms/Registry/StrategyContractsTest.php
git commit -m "refactor(platforms): ConnectResult two-stage connect contract + generic multi-account path (FOUND-24)"
```

---

### Task 2: Migrate the oEmbed trio — Spotify, SoundCloud, Deezer

**Files:**
- Create: `app/Services/Platforms/Strategies/Connect/SpotifyConnect.php`
- Create: `app/Services/Platforms/Strategies/Connect/SoundcloudConnect.php`
- Create: `app/Services/Platforms/Strategies/Connect/DeezerConnect.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (register strategies, null the connectControllers, drop 3 imports)
- Delete: `app/Http/Controllers/Api/Platforms/{Spotify,Soundcloud,Deezer}Controller.php`
- Verify only: `tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php` (asserts connectController for LinkOnly/SingleSelection groups only — no MultiAccount edits needed)
- Test (existing net): `tests/Feature/Platforms/IntegrationsV2ConnectionTest.php`, `IntegrationsV3ConnectionTest.php`, `GoldenMaster/IntegrationContractGoldenMasterTest.php`

**Interfaces:**
- Consumes: `ConnectResult::ok/fail`, `ConnectStrategy::resolve` (Task 1); `OEmbedService::resolve(string $oembedUrl): ?array{name,thumbnail,embedUrl?}`; `DeezerApi::parseArtistId/fetchArtist/embedUrlForArtist`; `PlatformInput::urlish/isBareToken/token`.
- Produces: three registered lazy connect strategies; descriptors `spotify`/`soundcloud`/`deezer` with `->routes(MultiAccount, null, true)`.

- [ ] **Step 1: SpotifyConnect** (connect body + private parseEntity moved verbatim from `SpotifyController`)

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Spotify connect: any entity link (artist/album/playlist/track/show/episode/
// user) → public oEmbed resolves name + artwork keylessly; the embed player URL
// is derived from entity type + id. Moved verbatim from SpotifyController.
class SpotifyConnect implements ConnectStrategy
{
    public function __construct(private readonly OEmbedService $oembed) {}

    public function resolve(string $input): ConnectResult
    {
        $entity = $this->parseEntity($input);
        if (! $entity) {
            return ConnectResult::fail(); // descriptor's parse-fail message
        }
        [$type, $id] = $entity;
        $link = "https://open.spotify.com/{$type}/{$id}";

        $resolved = $this->oembed->resolve('https://open.spotify.com/oembed?url='.rawurlencode($link));
        if ($resolved === null) {
            return ConnectResult::fail('Could not load that Spotify link.');
        }

        return ConnectResult::ok([
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The embed URL is deterministic; oEmbed's iframe_url is preferred
            // but the constructed form covers a missing field.
            'embedUrl' => $resolved['embedUrl'] ?? "https://open.spotify.com/embed/{$type}/{$id}",
            'link' => $link,
        ]);
    }

    /** @return array{0:string, 1:string}|null [type, id] from any entity link. */
    private function parseEntity(string $url): ?array
    {
        if (preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(artist|album|playlist|track|show|episode|user)/([A-Za-z0-9]+)~i', PlatformInput::urlish($url), $m)) {
            return [strtolower($m[1]), $m[2]];
        }

        return null;
    }
}
```

- [ ] **Step 2: SoundcloudConnect** (same treatment; `canonicalUrl` moved verbatim from `SoundcloudController`)

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// SoundCloud connect: profile / track / set link → public oEmbed resolves the
// display name + artwork; widget URL parsed from the oEmbed html with a
// deterministic w.soundcloud.com fallback. Moved verbatim from SoundcloudController.
class SoundcloudConnect implements ConnectStrategy
{
    public function __construct(private readonly OEmbedService $oembed) {}

    public function resolve(string $input): ConnectResult
    {
        $link = $this->canonicalUrl($input);
        if (! $link) {
            return ConnectResult::fail();
        }

        $resolved = $this->oembed->resolve('https://soundcloud.com/oembed?format=json&url='.rawurlencode($link));
        if ($resolved === null) {
            return ConnectResult::fail('Could not load that SoundCloud link.');
        }

        return ConnectResult::ok([
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The widget accepts permalink URLs directly, so a missing oEmbed
            // iframe still yields a working player.
            'embedUrl' => $resolved['embedUrl'] ?? 'https://w.soundcloud.com/player/?url='.rawurlencode($link).'&visual=true',
            'link' => $link,
        ]);
    }

    /** soundcloud.com path (≤3 segments) → canonical https link, else null. */
    private function canonicalUrl(string $url): ?string
    {
        $url = PlatformInput::urlish($url);

        if (preg_match('~^https?://(?:www\.|m\.)?soundcloud\.com(/[a-z0-9_-]+(?:/[a-z0-9_-]+){0,2})~i', $url, $m)) {
            return 'https://soundcloud.com'.strtolower(rtrim($m[1], '/'));
        }

        // A bare profile name maps straight onto soundcloud.com/{name}.
        if (PlatformInput::isBareToken($url, '~^[a-z0-9_-]{3,40}$~i')) {
            return 'https://soundcloud.com/'.strtolower(PlatformInput::token($url));
        }

        return null;
    }
}
```

- [ ] **Step 3: DeezerConnect**

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Deezer connect: artist link → open JSON API resolves name + artwork; the
// official widget embeds keylessly. Moved verbatim from DeezerController.
class DeezerConnect implements ConnectStrategy
{
    public function __construct(private readonly DeezerApi $deezer) {}

    public function resolve(string $input): ConnectResult
    {
        $id = $this->deezer->parseArtistId($input);
        if (! $id) {
            return ConnectResult::fail();
        }

        $artist = $this->deezer->fetchArtist($id);
        if ($artist === null) {
            return ConnectResult::fail('Could not load that Deezer artist.');
        }

        return ConnectResult::ok([
            'url' => $artist['link'],
            'artistId' => $id,
            'name' => $artist['name'],
            'thumbnail' => $artist['thumbnail'],
            'embedUrl' => DeezerApi::embedUrlForArtist($id),
            'link' => $artist['link'],
        ]);
    }
}
```

- [ ] **Step 4: Register + rewire in the provider**

`app/Providers/PlatformRegistryServiceProvider.php`:
- Add near the oEmbed fetch registrations (lazy Closures — mandatory):

```php
            // Connect strategies (FOUND-24) — parse-fail messages are the frozen
            // 422 contract, copied verbatim from the deleted controllers.
            $r->get('spotify')->connect(fn () => new SpotifyConnect(app(OEmbedService::class)), 'Enter a Spotify link (open.spotify.com/artist/...).');
            $r->get('soundcloud')->connect(fn () => new SoundcloudConnect(app(OEmbedService::class)), 'Enter your SoundCloud link (soundcloud.com/yourname).');
            $r->get('deezer')->connect(fn () => new DeezerConnect(app(DeezerApi::class)), 'Enter a Deezer artist link (deezer.com/artist/...).');
```

- Change the three route-shape lines to null controllers:

```php
            $r->get('spotify')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('soundcloud')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('deezer')->routes(PlatformRouteShape::MultiAccount, null, true);
```

- Remove the now-unused `use App\Http\Controllers\Api\Platforms\{Spotify,Soundcloud,Deezer}Controller;` imports; add the three strategy imports.

- [ ] **Step 5: Delete the three controllers**

```bash
git rm app/Http/Controllers/Api/Platforms/SpotifyController.php \
       app/Http/Controllers/Api/Platforms/SoundcloudController.php \
       app/Http/Controllers/Api/Platforms/DeezerController.php
composer dump-autoload -o
```

- [ ] **Step 6: Verify RegistryRouteShapeTest** — it asserts `connectController()` only for the LinkOnly and SingleSelection groups, NOT the MultiAccount group, so spotify/soundcloud/deezer need **no edit** there. Run it to confirm: `./vendor/bin/pest tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php`.

- [ ] **Step 7: Run the platform suite, then full suite**

Run: `./vendor/bin/pest tests/Feature/Platforms`
Expected: PASS — spotify/soundcloud/deezer connect tests (V2/V3) hit the generic controller and return identical shapes, incl. the multi-account `id` key and the cap-422.
Run: `composer test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(platforms): migrate spotify/soundcloud/deezer connect onto ConnectStrategy, delete controllers (FOUND-24)"
```

---

### Task 3: Migrate the scraper pair — Twitch, Pinterest

**Files:**
- Create: `app/Services/Platforms/Strategies/Connect/TwitchConnect.php`
- Create: `app/Services/Platforms/Strategies/Connect/PinterestConnect.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Delete: `app/Http/Controllers/Api/Platforms/{Twitch,Pinterest}Controller.php`
- Verify only: `tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php` (no MultiAccount connectController assertions)

**Interfaces:**
- Consumes: `TwitchScraper::parseLogin/fetchChannel`, `PinterestScraper::parseUsername/fetchProfile/fetchPins`, Task 1 contracts.
- Produces: registered `twitch`/`pinterest` connect strategies; both descriptors `->routes(MultiAccount, null, <existing multiAccount flag>)` (twitch `true`, pinterest `false` — copy the current flags).

- [ ] **Step 1: TwitchConnect** — note the fetch-fail is a **404** (frozen contract):

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\TwitchScraper;

// Twitch connect: channel URL or handle → og tags provide display name,
// avatar, bio. Moved verbatim from TwitchController.
class TwitchConnect implements ConnectStrategy
{
    public function __construct(private readonly TwitchScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $login = $this->scraper->parseLogin($input);
        if (! $login) {
            return ConnectResult::fail();
        }

        $channel = $this->scraper->fetchChannel($login);
        if ($channel === null) {
            return ConnectResult::fail('Could not find that Twitch channel.', 404);
        }

        return ConnectResult::ok([
            'url' => "https://www.twitch.tv/{$login}",
            'login' => $login,
            'name' => $channel['name'],
            'image' => $channel['image'],
            'description' => $channel['description'],
        ]);
    }
}
```

- [ ] **Step 2: PinterestConnect** — fetch-fail is also **404**:

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\PinterestScraper;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Pinterest connect: profile URL or handle → state JSON provides name / avatar /
// follower count; public RSS provides the latest pins. Moved verbatim from
// PinterestController.
class PinterestConnect implements ConnectStrategy
{
    public function __construct(private readonly PinterestScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $username = $this->scraper->parseUsername($input);
        if (! $username) {
            return ConnectResult::fail();
        }

        $profile = $this->scraper->fetchProfile($username);
        if ($profile === null) {
            return ConnectResult::fail('Could not find that Pinterest profile.', 404);
        }
        $pins = $this->scraper->fetchPins($username);

        return ConnectResult::ok([
            'url' => "https://www.pinterest.com/{$username}/",
            'username' => $username,
            'name' => $profile['name'],
            'image' => $profile['image'],
            'followers' => $profile['followers'],
            'latest' => $pins[0] ?? null,
            'items' => $pins,
        ]);
    }
}
```

- [ ] **Step 3: Provider wiring** — parse-fail messages verbatim:

```php
            $r->get('twitch')->connect(fn () => new TwitchConnect(app(TwitchScraper::class)), 'Enter your Twitch channel (twitch.tv/yourname).');
            $r->get('pinterest')->connect(fn () => new PinterestConnect(app(PinterestScraper::class)), 'Enter your Pinterest profile (pinterest.com/yourname).');
            $r->get('twitch')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('pinterest')->routes(PlatformRouteShape::MultiAccount, null, false);
```

(Replace the two existing `->routes(...)` lines; drop the two controller imports.)

- [ ] **Step 4: Delete controllers, dump autoload, run suites** (RegistryRouteShapeTest needs no edit for MultiAccount platforms — verify only)

```bash
git rm app/Http/Controllers/Api/Platforms/TwitchController.php app/Http/Controllers/Api/Platforms/PinterestController.php
composer dump-autoload -o
./vendor/bin/pest tests/Feature/Platforms
composer test
```
Expected: PASS (V3 twitch/pinterest connect + 404 tests green through the generic path).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(platforms): migrate twitch/pinterest connect onto ConnectStrategy, delete controllers (FOUND-24)"
```

---

### Task 4: Migrate Strava (read path moves too — needs FeedPayload keys)

Strava is the one platform whose **reads** are still bespoke (`SingleSelection` shape). Moving it to the generic read path routes its payload through a DTO, so `FeedPayload` must carry Strava's `location` + `members` keys or the frozen 6-key contract (`tests/Feature/Platforms/StravaAndCustomLinksContractTest.php`) breaks.

**Files:**
- Modify: `app/Services/Platforms/Payloads/FeedPayload.php` (add `location`, `members`)
- Create: `app/Services/Platforms/Strategies/Connect/StravaConnect.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Delete: `app/Http/Controllers/Api/Platforms/StravaController.php`
- Modify: `tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php`

**Interfaces:**
- Consumes: `StravaClubScraper::normalizeUrl(string): ?string`, `fetchClub(string): ?array{name,location,image,description,members}`.
- Produces: `FeedPayload` gains `public ?string $location` and `public int|string|null $members`; strava descriptor `->payload(FeedPayload::class)->routes(MultiAccount, null, false)` + connect strategy.

- [ ] **Step 1: Extend FeedPayload** — add to the constructor, `fromArray()`, and `toArray()` (following the exact existing style):

```php
            public ?string $location,          // constructor, after $releaseDate
            public int|string|null $members,   // after $followers
```
```php
            location: self::stringOrNull($payload['location'] ?? null),
            members: self::intStringOrNull($payload['members'] ?? null),
```
```php
            'location' => $this->location,
            'members' => $this->members,
```

Update the class docblock's platform list to mention strava. Check `tests/Unit/Platforms/Payloads/` for a FeedPayload unit test and extend its round-trip expectations with the two new keys.

- [ ] **Step 2: StravaConnect**

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\StravaClubScraper;

// Strava connect: club URL (athlete profiles are login-walled) → club page
// provides name, location, photo, live member count. Moved verbatim from
// StravaController.
class StravaConnect implements ConnectStrategy
{
    public function __construct(private readonly StravaClubScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $url = $this->scraper->normalizeUrl($input);
        if (! $url) {
            return ConnectResult::fail();
        }

        $club = $this->scraper->fetchClub($url);
        if ($club === null) {
            return ConnectResult::fail('Could not read that Strava club page.', 404);
        }

        return ConnectResult::ok(['url' => $url, ...$club]);
    }
}
```

- [ ] **Step 3: Provider wiring** — strava moves shape `SingleSelection → MultiAccount(null, false)` and gains a payload class:

```php
            $r->get('strava')->connect(fn () => new StravaConnect(app(StravaClubScraper::class)), 'Enter your Strava club URL (strava.com/clubs/yourclub).');
            $r->get('strava')->payload(FeedPayload::class);
            $r->get('strava')->routes(PlatformRouteShape::MultiAccount, null, false);
```

(Replace the existing `$r->get('strava')->routes(SingleSelection, StravaController::class)` line; drop the import.)

- [ ] **Step 4: Delete controller; verify the read parity carefully**

```bash
git rm app/Http/Controllers/Api/Platforms/StravaController.php
composer dump-autoload -o
./vendor/bin/pest tests/Feature/Platforms/StravaAndCustomLinksContractTest.php tests/Feature/Platforms/IntegrationsV3ConnectionTest.php
```
Expected: PASS. The contract test's "strips unknown stored keys" assertion is the proof the DTO round-trip is lossless for the 6 resource keys. If `location`/`members` come back null, the FeedPayload additions are wrong — stop and fix.

- [ ] **Step 5: Update RegistryRouteShapeTest — move `strava` from the `$single` (SingleSelection) group into the multi-account-false group — then full suite, commit**

```bash
composer test
git add -A
git commit -m "refactor(platforms): migrate strava onto ConnectStrategy + generic reads via FeedPayload (FOUND-24)"
```

---

### Task 5: Migrate the reservations trio — NowBookit, ResDiary, OpenTable (keep suggestion endpoint)

**Files:**
- Create: `app/Services/Platforms/Strategies/Connect/NowBookitConnect.php`
- Create: `app/Services/Platforms/Strategies/Connect/ResDiaryConnect.php`
- Create: `app/Services/Platforms/Strategies/Connect/OpenTableConnect.php`
- Modify: `app/Http/Controllers/Api/Platforms/OpenTableController.php` (shrink to `suggestion()` only)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Delete: `app/Http/Controllers/Api/Platforms/{NowBookit,ResDiary}Controller.php`
- Verify only: `tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php` (no MultiAccount connectController assertions)

**Interfaces:**
- Consumes: `NowBookitService::isNowBookitUrl/parseIds/nameFromUrl/embedUrl`, `ResDiaryService::isResDiaryUrl/embedUrl/parseMicrosite/nameFromUrl`, `OpenTableService::isOpenTableUrl/parseRid/nameFromUrl/embedUrl/hostOf`.
- Produces: three registered connect strategies; descriptors `->routes(MultiAccount, null, false)`; `OpenTableController` retains ONLY `suggestion()` (its standalone route at `routes/api/platforms.php:331` is unchanged).

- [ ] **Step 1: NowBookitConnect** — both failures are 422; second message is bespoke:

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\NowBookitService;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// NowBookit connect: booking link → accountid + venueid read straight from the
// URL's query string (no fetch). Moved verbatim from NowBookitController.
class NowBookitConnect implements ConnectStrategy
{
    public function __construct(private readonly NowBookitService $service) {}

    public function resolve(string $input): ConnectResult
    {
        if (! $this->service->isNowBookitUrl($input)) {
            return ConnectResult::fail();
        }

        $ids = $this->service->parseIds($input);
        if ($ids === null) {
            return ConnectResult::fail('That link is missing the venue details. Use your NowBookit booking link that includes accountid and venueid.');
        }

        return ConnectResult::ok([
            'url' => $input,
            'accountId' => $ids['accountId'],
            'venueId' => $ids['venueId'],
            'name' => $this->service->nameFromUrl($input),
            'embedUrl' => $this->service->embedUrl($ids['accountId'], $ids['venueId']),
            'source' => 'manual',
        ]);
    }
}
```

- [ ] **Step 2: ResDiaryConnect**

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\ResDiaryService;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// ResDiary connect: booking/widget link → standard widget URL (no fetch).
// Moved verbatim from ResDiaryController.
class ResDiaryConnect implements ConnectStrategy
{
    public function __construct(private readonly ResDiaryService $service) {}

    public function resolve(string $input): ConnectResult
    {
        if (! $this->service->isResDiaryUrl($input)) {
            return ConnectResult::fail();
        }

        $embedUrl = $this->service->embedUrl($input);
        if ($embedUrl === null) {
            return ConnectResult::fail("That doesn't look like a ResDiary booking page. Paste your ResDiary booking or widget link.");
        }

        return ConnectResult::ok([
            'url' => $input,
            'microsite' => $this->service->parseMicrosite($input),
            'name' => $this->service->nameFromUrl($input),
            'embedUrl' => $embedUrl,
            // A manual (re)connect un-tags a Google-Business-seeded row so it drops
            // out of the connect modal's "Automatically Synced" undo list.
            'source' => 'manual',
        ]);
    }
}
```

- [ ] **Step 3: OpenTableConnect**

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// OpenTable connect: restaurant link → rid read from the URL; the keyless
// widget embeds availability. OpenTable WAF-blocks our servers, so slug-only
// links are rejected with a nudge. Moved verbatim from OpenTableController.
class OpenTableConnect implements ConnectStrategy
{
    public function __construct(private readonly OpenTableService $service) {}

    public function resolve(string $input): ConnectResult
    {
        if (! $this->service->isOpenTableUrl($input)) {
            return ConnectResult::fail();
        }

        $rid = $this->service->parseRid($input);
        if ($rid === null) {
            return ConnectResult::fail("That link doesn't include the restaurant id. Use the profile link with the number — opentable.com.au/restaurant/profile/123456.");
        }

        return ConnectResult::ok([
            'url' => $input,
            'rid' => $rid,
            'name' => $this->service->nameFromUrl($input),
            'embedUrl' => $this->service->embedUrl($rid, $this->service->hostOf($input)),
            // A manual (re)connect un-tags a Google-Business-seeded row so it drops
            // out of the connect modal's "Automatically Synced" undo list.
            'source' => 'manual',
        ]);
    }
}
```

- [ ] **Step 4: Shrink OpenTableController to the suggestion endpoint only**

Replace the whole class body of `app/Http/Controllers/Api/Platforms/OpenTableController.php` with:

```php
<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// OpenTable's one bespoke endpoint. connect/selection/forget are registry-driven
// (OpenTableConnect strategy + GenericPlatformController); this survives because
// it reads ACROSS platforms (the Google Business connection), which the generic
// shape has no seam for.
class OpenTableController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly OpenTableService $service) {}

    // GET /api/platforms/opentable/suggestion
    // The OpenTable profile link (with the rid) already harvested from the
    // user's Google Business connection, so the dashboard can offer a one-click
    // connect — OpenTable blocks us from resolving slug links ourselves.
    public function suggestion(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $gb = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::GoogleBusiness->value)
            ->first();

        $suggestion = $gb
            ? $this->service->suggestionFromGoogleBusiness(GoogleBusinessPayload::fromArray($gb->payload)->toArray())
            : null;

        return $this->success(['suggestion' => $suggestion]);
    }
}
```

- [ ] **Step 5: Provider wiring** (replace the three `->routes(...)` lines, drop the NowBookit/ResDiary controller imports — keep OpenTableController out of the provider entirely):

```php
            $r->get('nowbookit')->connect(fn () => new NowBookitConnect(app(NowBookitService::class)), 'Enter a NowBookit booking link (nowbookit.com/...).');
            $r->get('resdiary')->connect(fn () => new ResDiaryConnect(app(ResDiaryService::class)), 'Enter a ResDiary booking link (resdiary.com/...).');
            $r->get('opentable')->connect(fn () => new OpenTableConnect(app(OpenTableService::class)), 'Enter an OpenTable restaurant link (opentable.com.au/...).');
            $r->get('nowbookit')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('resdiary')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('opentable')->routes(PlatformRouteShape::MultiAccount, null, false);
```

- [ ] **Step 6: Delete controllers, prune the NoUntypedPayloadAccessTest exempt list, run suites**

```bash
git rm app/Http/Controllers/Api/Platforms/NowBookitController.php app/Http/Controllers/Api/Platforms/ResDiaryController.php
composer dump-autoload -o
```
Check `tests/Feature/Platforms/NoUntypedPayloadAccessTest.php` — remove exempt entries pointing at deleted files (keep `OpenTableController.php` only if the shrunken file still trips the scan; verify by running the test).

```bash
./vendor/bin/pest tests/Feature/Platforms/ReservationProvidersTest.php tests/Feature/Platforms/OpenTableConnectionTest.php
composer test
```
Expected: PASS — reservation connects (real-URL parsing, no mocks) return identical 4/5-key shapes + the suggestion endpoint is untouched.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(platforms): migrate reservations trio onto ConnectStrategy; OpenTableController keeps only suggestion (FOUND-24)"
```

---

### Task 6: HighlightsStrategy contract + generic recent/highlights endpoints + route emission

**Files:**
- Create: `app/Services/Platforms/Strategies/Contracts/HighlightsStrategy.php`
- Create: `app/Http/Requests/Platforms/PlatformHighlightsRequest.php`
- Move: `app/Http/Controllers/Api/Platforms/Concerns/RefreshesLatestTile.php` → `app/Services/Platforms/Concerns/RefreshesLatestTile.php` (namespace `App\Services\Platforms\Concerns`; update imports in `AppleController`, `YoutubeController`, `BandcampController`)
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php` (highlights factory; replace the Task-1 stub)
- Modify: `app/Http/Controllers/Api/Platforms/GenericPlatformController.php` (add `recent()`, `highlights()`)
- Modify: `routes/api/platforms.php` (loop emits `/recent` + `/highlights`)

**Interfaces:**
- Consumes: `ManagesIntegrationConnection::requestedAccountRow/withConnectionLock/writeConnection`, `ResolvesConnectRules`-style descriptor resolution.
- Produces (later tasks implement):

```php
interface HighlightsStrategy
{
    public function identity(array $payload): ?string;              // re-fetch key; null → 404 "connect first"
    public function recentItems(string $identity): ?array;          // fresh picker items; null → 422 load error
    public function apply(array $selection, array $items, array $chosenIds): array; // merged selection to store
    public function requestField(): string;                         // 'videoIds' | 'itemIds' (frozen)
    /** @return array<string, array<int, string>> full validation rules (frozen per platform) */
    public function rules(): array;
    public function responseKey(): string;                          // 'videos' | 'items'
    public function notConnectedMessage(): string;
    public function loadErrorMessage(): string;
}
```

- `PlatformDescriptor::highlights(HighlightsStrategy|Closure $strategy): self`, `highlightsStrategy(): ?HighlightsStrategy`, `hasHighlights(): bool` (checks the factory property WITHOUT invoking it).

- [ ] **Step 1: Move RefreshesLatestTile** (strategies will consume it; a controller concern under Services is wrong-way coupling)

```bash
mkdir -p app/Services/Platforms/Concerns
git mv app/Http/Controllers/Api/Platforms/Concerns/RefreshesLatestTile.php app/Services/Platforms/Concerns/RefreshesLatestTile.php
```
Change its namespace to `App\Services\Platforms\Concerns`; update the `use` lines in `AppleController.php`, `YoutubeController.php`, `BandcampController.php`. Run `composer dump-autoload -o && ./vendor/bin/pest tests/Feature/Platforms` — green before continuing.

- [ ] **Step 2: Create the HighlightsStrategy contract** (code above, at `app/Services/Platforms/Strategies/Contracts/HighlightsStrategy.php`, with a docblock explaining: identity/recentItems split exists because "no identity stored" is a 404-connect-first and "upstream fetch failed" is a 422 — the two frozen error paths of the four picker platforms).

- [ ] **Step 3: Descriptor highlights factory** — replace the Task-1 `hasHighlights()` stub:

```php
    /** @var (Closure(): HighlightsStrategy)|null Lazily builds the highlights strategy (same rationale as fetch()). */
    private ?Closure $highlightsFactory = null;

    public function highlights(HighlightsStrategy|Closure $strategy): self
    {
        $this->highlightsFactory = $strategy instanceof Closure ? $strategy : fn () => $strategy;

        return $this;
    }

    public function highlightsStrategy(): ?HighlightsStrategy
    {
        return $this->highlightsFactory !== null ? ($this->highlightsFactory)() : null;
    }

    /** Boot-safe: the route loop calls this while emitting routes — it must
     *  never resolve the factory (that would eager-load the scraper). */
    public function hasHighlights(): bool
    {
        return $this->highlightsFactory !== null;
    }
```

- [ ] **Step 4: PlatformHighlightsRequest**

```php
<?php

namespace App\Http\Requests\Platforms;

use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Foundation\Http\FormRequest;

// The single highlights-save request for every picker platform. Field name +
// rules come from the descriptor's HighlightsStrategy resolved off the route's
// 'platform' default (mirrors PlatformConnectRequest). 404 fail-closed when the
// platform is unknown or has no highlights strategy.
class PlatformHighlightsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $platform = $this->route('platform');
        abort_if(! is_string($platform) || $platform === '', 404);

        $strategy = app(PlatformRegistry::class)->get($platform)?->highlightsStrategy();
        abort_if($strategy === null, 404);

        return $strategy->rules();
    }
}
```

- [ ] **Step 5: Generic recent() + highlights()** — add to `GenericPlatformController` (below `connect()`):

```php
    // GET /api/platforms/{platform}/recent?account={id} — fresh picker items for
    // the requested account (first account when no id is given).
    public function recent(Request $request): JsonResponse
    {
        $strategy = $this->descriptor()->highlightsStrategy();
        abort_if($strategy === null, 404);

        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        $identity = $strategy->identity($row?->payload ?? []);
        if ($identity === null) {
            return $this->error($strategy->notConnectedMessage(), 404);
        }

        $items = $strategy->recentItems($identity);
        if ($items === null) {
            return $this->error($strategy->loadErrorMessage(), 422);
        }

        return $this->success([$strategy->responseKey() => $items]);
    }

    // POST /api/platforms/{platform}/highlights?account={id} — snapshot the
    // chosen items onto that account's stored selection (empty list clears).
    // Locked read→mutate→write, mirroring the deleted per-platform controllers.
    public function highlights(PlatformHighlightsRequest $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $strategy = $descriptor->highlightsStrategy();
        abort_if($strategy === null, 404);

        $validated = $request->validated();
        $user = $this->currentUser($request);
        $accountId = $request->query('account');

        return $this->withConnectionLock($user, function () use ($user, $descriptor, $strategy, $validated, $accountId): JsonResponse {
            $row = $this->requestedAccountRow($user, $accountId);
            $selection = $row?->payload;
            if (! $row || ! $selection) {
                return $this->error($strategy->notConnectedMessage(), 404);
            }

            $identity = $strategy->identity($selection);
            if ($identity === null) {
                return $this->error($strategy->notConnectedMessage(), 404);
            }

            $items = $strategy->recentItems($identity);
            if ($items === null) {
                return $this->error($strategy->loadErrorMessage(), 422);
            }

            $selection = $strategy->apply($selection, $items, $validated[$strategy->requestField()]);
            $this->writeConnection($user, $selection, $row->resource_id);

            $resourceClass = $descriptor->resourceClass();

            return $this->success(['id' => $row->resource_id, ...(new $resourceClass($selection))->resolve()]);
        });
    }
```

Add imports: `PlatformHighlightsRequest`.

- [ ] **Step 6: Route-loop emission** — in `routes/api/platforms.php`, inside the registry loop after the `multiAccount()` block:

```php
                // Picker platforms: recent + curated highlights, strategy-driven.
                if ($descriptor->hasHighlights()) {
                    Route::get('/recent', [GenericPlatformController::class, 'recent'])->defaults('platform', $slug);
                    Route::post('/highlights', [GenericPlatformController::class, 'highlights'])->defaults('platform', $slug);
                }
```

- [ ] **Step 7: Run full suite** (no descriptor has highlights yet — emission is a no-op; everything must stay green, including the golden master's 57-route pin)

Run: `composer test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(platforms): HighlightsStrategy contract + registry-driven recent/highlights endpoints (FOUND-24)"
```

---

### Task 7: Migrate YouTube (first picker platform)

**Files:**
- Create: `app/Services/Platforms/Strategies/Connect/YoutubeConnect.php`
- Create: `app/Services/Platforms/Strategies/Highlights/YoutubeHighlights.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/platforms.php` (delete the youtube group at ~lines 108–120 + the `YoutubeController` import)
- Delete: `app/Http/Controllers/Api/Platforms/YoutubeController.php`, `app/Http/Requests/Platforms/SaveYoutubeHighlightsRequest.php`
- Modify: `tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php`

**Interfaces:**
- Consumes: `YoutubeScraper::normalizeHandle/fetchRecentVideos`, `RefreshesLatestTile` (moved in Task 6), `FeedPayload::fromArray(...)->handle`, Task 6 `HighlightsStrategy`.
- Produces: youtube descriptor with `->connect(...)`, `->highlights(...)`, `->routes(MultiAccount, null, true)`.

- [ ] **Step 1: YoutubeConnect** — note `accountKey` is the handle (matches the base default, passed explicitly for clarity) and the selection does NOT include `highlights` (the generic controller injects the preserved list):

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\YoutubeScraper;

// YouTube connect: channel handle/URL → channel's auto-latest video tile.
// Moved verbatim from YoutubeController; the curated-highlights preservation
// happens in GenericPlatformController (hasHighlights + preserveHighlights).
class YoutubeConnect implements ConnectStrategy
{
    use RefreshesLatestTile;

    private const FLAT_TILE_FIELDS = ['name', 'description', 'link', 'thumbnail'];

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $handle = $this->scraper->normalizeHandle($input);
        if ($handle === '') {
            return ConnectResult::fail();
        }

        $videos = $this->scraper->fetchRecentVideos($handle);
        if (empty($videos)) {
            return ConnectResult::fail('Could not find that YouTube channel or its latest video.', 404);
        }
        $latest = $videos[0];

        return ConnectResult::ok([
            'handle' => $handle,
            // Flat fields retained for partna-pages + back-compat; nested
            // `latest` is the canonical shape.
            ...$this->flatTileFields($latest, self::FLAT_TILE_FIELDS),
            'latest' => $latest,
        ], $handle);
    }
}
```

- [ ] **Step 2: YoutubeHighlights**

```php
<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\YoutubeScraper;

// YouTube picker: last 15 videos; up to 5 curated highlights; the "Most
// recent" tile (+ flat back-compat fields) refreshes on every save. Moved
// verbatim from YoutubeController::recent/highlights.
class YoutubeHighlights implements HighlightsStrategy
{
    use RefreshesLatestTile;

    private const MAX_HIGHLIGHTS = 5;

    private const FLAT_TILE_FIELDS = ['name', 'description', 'link', 'thumbnail'];

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function identity(array $payload): ?string
    {
        return FeedPayload::fromArray($payload)->handle;
    }

    public function recentItems(string $identity): ?array
    {
        return $this->scraper->fetchRecentVideos($identity);
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // A video published since connect must refresh `latest` + flat fields,
        // not just the highlights (CONS-1).
        if (isset($items[0])) {
            $selection = $this->refreshLatestTile($selection, $items[0], self::FLAT_TILE_FIELDS);
        }

        $byId = collect($items)->keyBy('videoId');
        $selection['highlights'] = collect($chosenIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();

        return $selection;
    }

    public function requestField(): string
    {
        return 'videoIds';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'videoIds' => ['present', 'array', 'max:5'],
            'videoIds.*' => ['string', 'max:30'],
        ];
    }

    public function responseKey(): string
    {
        return 'videos';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a YouTube channel first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent videos for that channel.';
    }
}
```

- [ ] **Step 3: Provider wiring**

```php
            $r->get('youtube')->connect(fn () => new YoutubeConnect(app(YoutubeScraper::class)), 'Enter your YouTube channel.');
            $r->get('youtube')->highlights(fn () => new YoutubeHighlights(app(YoutubeScraper::class)));
            $r->get('youtube')->routes(PlatformRouteShape::MultiAccount, null, true);
```

(youtube previously had NO `->routes(...)` line — it was Bespoke by default. This line is new, not a replacement.)

- [ ] **Step 4: Delete the hand-written route group + controller + request**

In `routes/api/platforms.php` delete the whole `Route::prefix("{$base}/youtube")...` group (the 7 routes) and the `YoutubeController` import.

```bash
git rm app/Http/Controllers/Api/Platforms/YoutubeController.php app/Http/Requests/Platforms/SaveYoutubeHighlightsRequest.php
composer dump-autoload -o
```

- [ ] **Step 5: Verify route identity, run the youtube tests, full suite**

```bash
php artisan route:list --json | grep -c "api/platforms/youtube"   # same URI set as before: connect, recent, highlights, accounts, accounts/{id}, selection, DELETE
./vendor/bin/pest tests/Feature/Platforms/ScraperPlatformsConnectionTest.php tests/Feature/Platforms/GoldenMaster
composer test
```
Expected: PASS. The golden master's 57-route count + URI list is the hard gate that the loop emitted byte-identical routes.

- [ ] **Step 6: Update RegistryRouteShapeTest — move `youtube` out of the `$bespoke` list into the multi-account-true group — and commit**

```bash
git add -A
git commit -m "refactor(platforms): youtube onto Connect+Highlights strategies, delete controller + route group (FOUND-24)"
```

---

### Task 8: Migrate Vimeo + YouTube Music (musicItems relocation)

**Files:**
- Create: `app/Services/Platforms/YoutubeMusicItems.php` (relocated `musicItems()`)
- Create: `app/Services/Platforms/Strategies/Connect/VimeoConnect.php`
- Create: `app/Services/Platforms/Strategies/Connect/YoutubeMusicConnect.php`
- Create: `app/Services/Platforms/Strategies/Highlights/VimeoHighlights.php`
- Create: `app/Services/Platforms/Strategies/Highlights/YoutubeMusicHighlights.php`
- Modify: `app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php:37` (call the relocated helper)
- Modify: `tests/Feature/Platforms/Strategies/FeedFetchParityTest.php:75,96` (same relocation)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`, `routes/api/platforms.php` (delete both groups + imports)
- Delete: `app/Http/Controllers/Api/Platforms/{Vimeo,YoutubeMusic}Controller.php`, `app/Http/Requests/Platforms/{SaveVimeoHighlightsRequest,SaveYoutubeMusicHighlightsRequest}.php`
- Modify: `tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php`

**Interfaces:**
- Consumes: `VimeoApi::parseSource/fetchProfile/fetchVideos`, `YoutubeScraper::channelIdFrom/fetchUploadsFeed`, `FeedPayload` (`apiPath`/`channelId`).
- Produces: `YoutubeMusicItems::map(array $videos): array` (the exact `musicItems` body, same docblock); vimeo/youtube-music descriptors with connect+highlights strategies, `->routes(MultiAccount, null, true)`.

- [ ] **Step 1: YoutubeMusicItems** — move `YoutubeMusicController::musicItems()` verbatim as `map()`:

```php
<?php

namespace App\Services\Platforms;

// Feed videos → the music item shape: links land on music.youtube.com and each
// item carries the standard YouTube embed for inline playback. Shared by the
// YouTube Music connect/highlights strategies and YoutubeMusicFetch (refresh).
final class YoutubeMusicItems
{
    /**
     * @param  list<array{videoId:string, name:string, link:string, date:?string, thumbnail:string}>  $videos
     * @return list<array{itemId:string, name:string, thumbnail:string, link:string, date:?string, embedUrl:string}>
     */
    public static function map(array $videos): array
    {
        return array_map(fn (array $v) => [
            'itemId' => $v['videoId'],
            'name' => $v['name'],
            'thumbnail' => $v['thumbnail'],
            'link' => 'https://music.youtube.com/watch?v='.$v['videoId'],
            // YT Music uses the uploads feed, so the upload <published> doubles as
            // the release date — carried through for chronological sorting.
            'date' => $v['date'] ?? null,
            'embedUrl' => 'https://www.youtube.com/embed/'.$v['videoId'],
        ], $videos);
    }
}
```

Update `YoutubeMusicFetch.php:37` to `YoutubeMusicItems::map($feed['videos'])`, **swap its `use App\Http\Controllers\Api\Platforms\YoutubeMusicController;` import for `use App\Services\Platforms\YoutubeMusicItems;`** (a leftover import of a deleted class is dead code no test catches), fix its line-14 comment, and update the `FeedFetchParityTest.php` references the same way. Also fix the stale "from YoutubeController" comment at `app/Services/Platforms/YoutubeScraper.php:10` ("from the youtube connect strategy"). Run `./vendor/bin/pest tests/Feature/Platforms/Strategies/FeedFetchParityTest.php` — green before continuing.

- [ ] **Step 2: VimeoConnect** — accountKey is the apiPath (was the controller's `accountKeyOf` override; must be passed explicitly):

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\VimeoApi;

// Vimeo connect: profile/channel URL → keyless Simple API provides name,
// avatar, latest uploads. apiPath is the canonical account identity (urls vary
// per input form) — passed as the accountKey. Moved verbatim from VimeoController.
class VimeoConnect implements ConnectStrategy
{
    private const MAX_ITEMS = 12;

    public function __construct(private readonly VimeoApi $vimeo) {}

    public function resolve(string $input): ConnectResult
    {
        $source = $this->vimeo->parseSource($input);
        if (! $source) {
            return ConnectResult::fail();
        }

        $profile = $this->vimeo->fetchProfile($source['apiPath']);
        $videos = $this->vimeo->fetchVideos($source['apiPath']);
        if ($profile === null && $videos === []) {
            return ConnectResult::fail('Could not find that Vimeo profile.', 404);
        }

        return ConnectResult::ok([
            'url' => $source['link'],
            'apiPath' => $source['apiPath'],
            'name' => $profile['name'] ?? null,
            'thumbnail' => $profile['thumbnail'] ?? ($videos[0]['thumbnail'] ?? null),
            'link' => $profile['link'] ?? $source['link'],
            'latest' => $videos[0] ?? null,
            'items' => array_slice($videos, 0, self::MAX_ITEMS),
        ], $source['apiPath']);
    }
}
```

- [ ] **Step 3: VimeoHighlights**

```php
<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\VimeoApi;

// Vimeo picker: latest uploads (keyless API caps a page at 20); up to 5
// curated highlights; latest tile + items grid refresh on every save. Moved
// verbatim from VimeoController::recent/highlights.
class VimeoHighlights implements HighlightsStrategy
{
    private const MAX_ITEMS = 12;

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly VimeoApi $vimeo) {}

    public function identity(array $payload): ?string
    {
        $apiPath = FeedPayload::fromArray($payload)->apiPath;

        return $apiPath !== null ? (string) $apiPath : null;
    }

    public function recentItems(string $identity): ?array
    {
        $videos = $this->vimeo->fetchVideos($identity);

        // Vimeo's frozen contract treats an empty page as a load failure.
        return $videos === [] ? null : $videos;
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // Keep the auto-latest tile + items grid fresh alongside the picks.
        // Profile name/avatar stay as connected — they aren't video fields.
        $selection['latest'] = $items[0];
        $selection['items'] = array_slice($items, 0, self::MAX_ITEMS);

        $byId = collect($items)->keyBy('itemId');
        $selection['highlights'] = collect($chosenIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();

        return $selection;
    }

    public function requestField(): string
    {
        return 'itemIds';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'itemIds' => ['present', 'array', 'max:5'],
            'itemIds.*' => ['string', 'max:30'],
        ];
    }

    public function responseKey(): string
    {
        return 'videos';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a Vimeo profile first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent videos for that profile.';
    }
}
```

- [ ] **Step 4: YoutubeMusicConnect + YoutubeMusicHighlights**

`YoutubeMusicConnect`:

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\YoutubeMusicItems;
use App\Services\Platforms\YoutubeScraper;

// YouTube Music connect: artist/channel URL or @handle → the channel's uploads
// RSS provides the releases, rewritten onto music.youtube.com. channelId is the
// canonical artist identity — passed as the accountKey. Moved verbatim from
// YoutubeMusicController.
class YoutubeMusicConnect implements ConnectStrategy
{
    private const MAX_ITEMS = 12;

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $channelId = $this->scraper->channelIdFrom($input);
        if (! $channelId) {
            return ConnectResult::fail();
        }

        $feed = $this->scraper->fetchUploadsFeed($channelId, self::MAX_ITEMS);
        if ($feed === null) {
            return ConnectResult::fail('Could not load releases for that channel.', 404);
        }

        $items = YoutubeMusicItems::map($feed['videos']);
        $url = 'https://music.youtube.com/channel/'.$channelId;

        return ConnectResult::ok([
            'url' => $url,
            'channelId' => $channelId,
            // Auto-generated artist channels are titled "<Artist> - Topic".
            'name' => $feed['title'] !== null ? preg_replace('/\s+-\s+Topic$/', '', $feed['title']) : null,
            'thumbnail' => $items[0]['thumbnail'] ?? null,
            'link' => $url,
            'latest' => $items[0] ?? null,
            'items' => $items,
        ], $channelId);
    }
}
```

`YoutubeMusicHighlights`:

```php
<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\YoutubeMusicItems;
use App\Services\Platforms\YoutubeScraper;

// YouTube Music picker: latest releases from the uploads feed (caps at 15);
// up to 5 curated highlights; latest tile + items grid refresh on every save.
// Moved verbatim from YoutubeMusicController::recent/highlights.
class YoutubeMusicHighlights implements HighlightsStrategy
{
    private const MAX_ITEMS = 12;

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function identity(array $payload): ?string
    {
        $channelId = FeedPayload::fromArray($payload)->channelId;

        return $channelId !== null ? (string) $channelId : null;
    }

    public function recentItems(string $identity): ?array
    {
        $feed = $this->scraper->fetchUploadsFeed($identity);
        if ($feed === null || $feed['videos'] === []) {
            return null;
        }

        return YoutubeMusicItems::map($feed['videos']);
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // Keep the auto-latest tile + items grid fresh alongside the picks.
        $selection['latest'] = $items[0] ?? null;
        $selection['items'] = array_slice($items, 0, self::MAX_ITEMS);

        $byId = collect($items)->keyBy('itemId');
        $selection['highlights'] = collect($chosenIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();

        return $selection;
    }

    public function requestField(): string
    {
        return 'itemIds';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // `present` (not `required`) so an empty array is a valid "clear
            // my highlights" submission.
            'itemIds' => ['present', 'array', 'max:5'],
            'itemIds.*' => ['string', 'max:30'],
        ];
    }

    public function responseKey(): string
    {
        return 'videos';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a YouTube Music artist first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent releases for that channel.';
    }
}
```

- [ ] **Step 5: Provider wiring** (both were Bespoke — the `->routes(...)` lines are new):

```php
            $r->get('vimeo')->connect(fn () => new VimeoConnect(app(VimeoApi::class)), 'Enter your Vimeo profile or channel URL (vimeo.com/yourname).');
            $r->get('vimeo')->highlights(fn () => new VimeoHighlights(app(VimeoApi::class)));
            $r->get('vimeo')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('youtube-music')->connect(fn () => new YoutubeMusicConnect(app(YoutubeScraper::class)), 'Enter your YouTube Music artist URL (music.youtube.com/channel/…) or your channel @handle.');
            $r->get('youtube-music')->highlights(fn () => new YoutubeMusicHighlights(app(YoutubeScraper::class)));
            $r->get('youtube-music')->routes(PlatformRouteShape::MultiAccount, null, true);
```

- [ ] **Step 6: Delete groups, controllers, requests; run suites**

Delete the `vimeo` and `youtube-music` route groups + imports from `routes/api/platforms.php`.

```bash
git rm app/Http/Controllers/Api/Platforms/VimeoController.php app/Http/Controllers/Api/Platforms/YoutubeMusicController.php \
       app/Http/Requests/Platforms/SaveVimeoHighlightsRequest.php app/Http/Requests/Platforms/SaveYoutubeMusicHighlightsRequest.php
composer dump-autoload -o
./vendor/bin/pest tests/Feature/Platforms/IntegrationsV3ConnectionTest.php tests/Feature/Platforms/IntegrationsV4AdditionsTest.php tests/Feature/Platforms/GoldenMaster
composer test
```
Expected: PASS — "vimeo recent + highlights mirror the youtube picker flow" and the youtube-music picker flow are the direct behavioral pins. Update `RegistryRouteShapeTest`: move `vimeo` + `youtube-music` out of the `$bespoke` list into the multi-account-true group.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(platforms): vimeo + youtube-music onto Connect+Highlights strategies (FOUND-24)"
```

---

### Task 9: Migrate Bandcamp (price enrichment)

**Files:**
- Create: `app/Services/Platforms/Strategies/Connect/BandcampConnect.php`
- Create: `app/Services/Platforms/Strategies/Highlights/BandcampHighlights.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`, `routes/api/platforms.php` (delete group + import)
- Delete: `app/Http/Controllers/Api/Platforms/BandcampController.php`, `app/Http/Requests/Platforms/SaveBandcampHighlightsRequest.php`
- Modify: `tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php`

**Interfaces:**
- Consumes: `BandcampScraper::normalizeOrigin/fetchProfile/enrichPrices`, `RefreshesLatestTile`, `FeedPayload->url`.
- Produces: bandcamp descriptor with connect+highlights strategies, `->routes(MultiAccount, null, true)`.

- [ ] **Step 1: BandcampConnect**

```php
<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Bandcamp connect: artist-page URL → latest release tile (price-enriched) +
// artist profile. accountKey is the canonical page origin. Moved verbatim from
// BandcampController.
class BandcampConnect implements ConnectStrategy
{
    use RefreshesLatestTile;

    // Flat back-compat tile fields copied verbatim from the latest release
    // (mirrors the Apple Music selection so sitepages render both identically).
    private const FLAT_FIELDS = ['name', 'thumbnail', 'link'];

    public function __construct(private readonly BandcampScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $origin = $this->scraper->normalizeOrigin($input);
        if (! $origin) {
            return ConnectResult::fail();
        }

        $profile = $this->scraper->fetchProfile($origin);
        if ($profile === null || $profile['items'] === []) {
            return ConnectResult::fail('Could not find releases on that Bandcamp page.', 404);
        }
        // Enrich the latest tile with its buy price (1 fetch). Null-safe.
        $latest = $this->scraper->enrichPrices([$profile['items'][0]])[0];

        $selection = [
            'url' => $origin,
            'artist' => $profile['name'],
            ...$this->flatTileFields($latest, self::FLAT_FIELDS),
            'latest' => $latest,
        ];
        // Prefer the latest release art for the tile; fall back to the page's
        // own og:image (artist avatar) when the release has none.
        $selection['thumbnail'] ??= $profile['thumbnail'];

        return ConnectResult::ok($selection, $origin);
    }
}
```

- [ ] **Step 2: BandcampHighlights**

```php
<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;

// Bandcamp picker: up to 15 releases; up to 5 curated highlights, each
// price-enriched (bounded concurrent fetch); the "Most recent" tile refreshes
// (price-enriched) on every save. Moved verbatim from BandcampController.
class BandcampHighlights implements HighlightsStrategy
{
    use RefreshesLatestTile;

    private const MAX_HIGHLIGHTS = 5;

    private const FLAT_FIELDS = ['name', 'thumbnail', 'link'];

    public function __construct(private readonly BandcampScraper $scraper) {}

    public function identity(array $payload): ?string
    {
        return FeedPayload::fromArray($payload)->url;
    }

    public function recentItems(string $identity): ?array
    {
        $profile = $this->scraper->fetchProfile($identity);
        if ($profile === null) {
            return null;
        }

        return array_slice($profile['items'], 0, 15);
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // Refresh the "Most recent" tile too — a release published since
        // connect would otherwise leave `latest` stale while only the
        // highlights updated.
        if (isset($items[0])) {
            $selection = $this->refreshLatestTile($selection, $this->scraper->enrichPrices([$items[0]])[0], self::FLAT_FIELDS);
        }

        $byId = collect($items)->keyBy('itemId');
        $chosen = collect($chosenIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();
        // Buy price for each curated highlight (bounded concurrent fetch).
        $selection['highlights'] = $this->scraper->enrichPrices($chosen, self::MAX_HIGHLIGHTS);

        return $selection;
    }

    public function requestField(): string
    {
        return 'itemIds';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'itemIds' => ['present', 'array', 'max:24'],
            'itemIds.*' => ['string', 'max:50'],
        ];
    }

    public function responseKey(): string
    {
        return 'items';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a Bandcamp page first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent releases.';
    }
}
```

Known micro-diff (accepted, document in the commit body): the old `highlights()` matched chosen ids against the **unsliced** profile items; the strategy matches against the same 15-item slice `/recent` serves. The picker only ever offers those 15, so no dashboard-reachable behavior changes.

- [ ] **Step 3: Provider wiring** (bandcamp was Bespoke — the `->routes(...)` line is new):

```php
            $r->get('bandcamp')->connect(fn () => new BandcampConnect(app(BandcampScraper::class)), 'Enter your Bandcamp page URL (yourname.bandcamp.com).');
            $r->get('bandcamp')->highlights(fn () => new BandcampHighlights(app(BandcampScraper::class)));
            $r->get('bandcamp')->routes(PlatformRouteShape::MultiAccount, null, true);
```

- [ ] **Step 4: Delete group + controller + request, update RegistryRouteShapeTest (move `bandcamp` out of `$bespoke` into the multi-account-true group), run suites, commit**

Delete the bandcamp route group + import from `routes/api/platforms.php`.

```bash
git rm app/Http/Controllers/Api/Platforms/BandcampController.php app/Http/Requests/Platforms/SaveBandcampHighlightsRequest.php
composer dump-autoload -o
./vendor/bin/pest tests/Feature/Platforms/IntegrationsV2ConnectionTest.php tests/Feature/Platforms/GoldenMaster
composer test
git add -A
git commit -m "refactor(platforms): bandcamp onto Connect+Highlights strategies, last picker platform (FOUND-24)"
```

---

### Task 10: Fold the base class into GoogleBusiness, delete SingleSelectionPlatformController

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php`
- Delete: `app/Http/Controllers/Api/Platforms/SingleSelectionPlatformController.php`

**Interfaces:**
- Consumes: `ManagesIntegrationConnection` trait (all storage methods GB uses come from the trait, not the base: `writeConnection`, `accountRows`, `forgetAllConnections`).
- Produces: GB extends `ApiController` directly; gains its own `forget()` and inlines `connected()`.

- [ ] **Step 1: Rewire GoogleBusinessController** — read the file first; the mechanical changes are:

1. `class GoogleBusinessController extends ApiController` + `use ManagesIntegrationConnection; use ResolveCurrentUser;` (add the imports; the base previously supplied both).
2. Keep `platform()` and `resourceClass()` as-is (the trait requires `platform()`; `resourceClass()` is now GB-private).
3. Replace the one `return $this->connected($user, $place);` call (~line 136) with the inlined single-selection body:

```php
        $this->writeConnection($user, $place);
        $resource = $this->resourceClass();

        return $this->success((new $resource($place))->resolve());
```

4. Add the `forget()` the base used to provide (its route is emitted by the SingleSelection branch of the loop):

```php
    // DELETE /api/platforms/google-business — clear every connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetAllConnections($this->currentUser($request));

        return $this->success(['selection' => null]);
    }
```

- [ ] **Step 2: Reword surviving comment references, then delete the base class**

First reword the comment at `app/Http/Controllers/Api/Platforms/GenericPlatformController.php:76` — its `selection()` docblock says "matches SingleSelectionPlatformController exactly"; change to "matches the deleted single-selection base exactly". (Task 1's `defaultAccountKey` docblock already avoids naming the class.) Then:

```bash
git rm app/Http/Controllers/Api/Platforms/SingleSelectionPlatformController.php
composer dump-autoload -o
grep -rn "SingleSelectionPlatformController" app/ tests/ routes/   # must return nothing
```

- [ ] **Step 3: Run GB tests + full suite, commit**

```bash
./vendor/bin/pest tests/Feature/Platforms/GoogleBusinessSelectionContractTest.php tests/Feature/Platforms/IntegrationsV3ConnectionTest.php
composer test
git add -A
git commit -m "refactor(platforms): fold single-selection base into GoogleBusinessController, delete the base class (FOUND-24)"
```

---

### Task 11: Architecture guard — one blessed way to add a platform

**Files:**
- Test: `tests/Feature/Architecture/PlatformControllerConvergenceTest.php` (NEW)

**Interfaces:**
- Consumes: `PlatformRegistry::all()`, `PlatformDescriptor::{routeShape,connectController,connectStrategy,connectField,resourceClass}`, `PlatformRouteShape`.
- Produces: CI enforcement that (a) no new per-platform controller appears outside the bespoke allowlist, (b) every registry-routed platform is fully descriptor-driven.

- [ ] **Step 1: Write the guard test**

```php
<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;

// FOUND-24 regression guard. There is exactly ONE way to add a platform:
// a PlatformDescriptor in PlatformRegistryServiceProvider (connectInput +
// connect strategy + routes(...) — plus highlights(...) for picker platforms).
// Hand-written per-platform controllers are reserved for the bespoke set below;
// adding a controller file = add a descriptor instead, or justify a new
// allowlist entry in this file.

const BESPOKE_CONTROLLER_ALLOWLIST = [
    'AppleController.php',            // dual music+podcast platforms in one controller
    'BookingController.php',          // smart-detect category facade
    'CustomLinksController.php',      // arbitrary link cards, not a platform
    'EventbriteController.php',       // events archetype (organiser accounts + standalone events)
    'EventsController.php',           // events smart-detect facade
    'EventsPlatformController.php',   // shared events base
    'FreshaController.php',           // picker flow (team/services selection)
    'GenericPlatformController.php',  // THE registry-driven controller
    'GoogleBusinessController.php',   // Places picker + cross-platform sync
    'HumanitixController.php',        // events archetype
    'InstagramController.php',        // async connect (job + poll)
    'IntegrationsMetaController.php', // cross-platform sync metadata
    'MenuController.php',             // menu view, no connect
    'OnlineOrderingController.php',   // ordering-links category
    'OpenTableController.php',        // suggestion() only — connect is registry-driven
    'RefreshController.php',          // manual refresh, cross-platform
    'ReservationsController.php',     // smart-detect category facade
    'ShopController.php',             // multi-brand picker
    'SkoolController.php',            // single-selection; needs a payload DTO before migrating
    'SquareController.php',           // XOR-with-fresha guard on connect
];

it('allows no per-platform controllers beyond the bespoke allowlist', function () {
    $files = collect(glob(app_path('Http/Controllers/Api/Platforms/*.php')))
        ->map(fn (string $path) => basename($path))
        ->sort()
        ->values();

    $unexpected = $files->diff(BESPOKE_CONTROLLER_ALLOWLIST)->values()->all();

    expect($unexpected)->toBe([], 'New platform controllers are forbidden — register a PlatformDescriptor '
        .'with a ConnectStrategy in PlatformRegistryServiceProvider instead (see FOUND-24). Unexpected: '
        .implode(', ', $unexpected));
});

it('keeps every allowlisted bespoke controller in existence (no stale entries)', function () {
    foreach (BESPOKE_CONTROLLER_ALLOWLIST as $file) {
        expect(file_exists(app_path('Http/Controllers/Api/Platforms/'.$file)))
            ->toBeTrue("Stale allowlist entry: {$file} no longer exists — remove it.");
    }
});

it('requires every registry-routed platform without a bespoke controller to be fully descriptor-driven', function () {
    $registry = app(PlatformRegistry::class);

    foreach ($registry->all() as $key => $descriptor) {
        if ($descriptor->routeShape() === PlatformRouteShape::Bespoke) {
            continue;
        }
        if ($descriptor->connectController() !== null) {
            continue; // SingleSelection bespoke connect (skool, google-business)
        }

        expect($descriptor->connectStrategy())->not->toBeNull("{$key}: registry-routed with no ConnectStrategy");
        expect($descriptor->connectField())->not->toBeNull("{$key}: registry-routed with no connectInput()");
        expect($descriptor->resourceClass())->not->toBeNull("{$key}: registry-routed with no resource()");
    }
});

it('has fully deleted the single-selection controller base', function () {
    expect(class_exists('App\Http\Controllers\Api\Platforms\SingleSelectionPlatformController'))->toBeFalse();
});
```

- [ ] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Feature/Architecture/PlatformControllerConvergenceTest.php`
Expected: PASS (this is the end-state pin; if any test fails, a prior task is incomplete — fix that task, don't edit the allowlist).

- [ ] **Step 3: Full suite + commit**

```bash
composer test
git add tests/Feature/Architecture/PlatformControllerConvergenceTest.php
git commit -m "test(architecture): guard the single descriptor-driven path for platform additions (FOUND-24)"
```

---

### Task 12: Audit-pipeline prose refresh + close FOUND-24

Per the CLAUDE.md freshness rule: on any architectural shift, refresh the audit prompts/lenses **first**, then grep lenses for the changed terms. (Research confirmed: zero controller class names appear in `scripts/audit/`; only one conceptually stale passage.)

**Files:**
- Modify: `scripts/audit/lenses/foundational-durability.md:19` (stale "~38 controllers" + per-platform fan-out framing)
- Modify: `scripts/audit/system-prompt.md` (add the strategy architecture to its platform description — read it first; if it describes per-platform controllers as the pattern, correct it; if it doesn't mention the pattern, add one sentence)
- Modify: `audits/sweeps/2026-07-04-foundational/CONSOLIDATED.md` (tick `#FOUND-24`, annotate)

**Interfaces:** none — prose only.

- [ ] **Step 1: Refresh the foundational-durability lens** — rewrite the line-19 passage to describe the converged architecture, e.g.:

> `app/Http/Controllers/Api/Platforms/` (~20 bespoke controllers) and `app/Services/Platforms/` (services + `Strategies/{Connect,Highlights,Fetch,Refresh,Detect}/`). The canonical "add a platform" path is ONE descriptor registration in `PlatformRegistryServiceProvider` (connectInput + ConnectStrategy + routes(), plus HighlightsStrategy for picker platforms) — flag any new per-platform controller or hand-written route group as a regression against `PlatformControllerConvergenceTest`.

Keep the surrounding question framing; verify the exact current wording before editing. Grep for other stale concepts:

```bash
grep -rn "SingleSelection\|per-platform controller\|copy.*controller" scripts/audit/
```
Fix any hits that describe the old pattern as current.

- [ ] **Step 2: Verify the integrity guard still passes**

Run: `./vendor/bin/pest tests/Feature/Architecture/AuditPipelineIntegrityTest.php`
Expected: PASS (paths in prose must all still exist).

- [ ] **Step 3: Tick FOUND-24** in `audits/sweeps/2026-07-04-foundational/CONSOLIDATED.md`: `- [ ] **#FOUND-24**` → `- [x] **#FOUND-24**`, and append a one-line annotation under the finding: `✅ Closed 2026-07-05 — full convergence: ConnectResult contract, 13 connect strategies, 4 highlights strategies, 12 controllers + base class + 4 route groups deleted, PlatformControllerConvergenceTest guards the single path. Plan: docs/superpowers/plans/2026-07-05-platform-connect-convergence.md`. Also update the `## Standalone` entry for FOUND-24 with the same ✅ marker (matching the FOUND-1 precedent in that file). If every checkbox in the folder is now `[x]`, run `scripts/audit/archive-done.sh`; otherwise leave the folder in place.

- [ ] **Step 4: Final full suite + style pass + commit**

```bash
vendor/bin/pint $(git diff --name-only development...HEAD -- '*.php' | tr '\n' ' ')
composer test
git add -A
git commit -m "docs(audit): refresh platform-architecture prose + close FOUND-24"
```

Then follow `superpowers:finishing-a-development-branch` — PR into `development` (never push without permission; delete branch + worktree after merge per standing preference).

---

## Verification summary (what proves each risk retired)

| Risk | Gate |
|---|---|
| Response shapes drift | Golden master `toEqual` on exact key sets, per platform |
| Route URIs drift | Golden master 57-route count + sorted URI list |
| 422/404 messages drift | V2/V3/V4 connection tests assert exact messages |
| Multi-account semantics drift (cap, dedupe, `id` key) | V2/V3 connect tests + new `RemoveAccountTest` |
| Strava DTO read-path loss | `StravaAndCustomLinksContractTest` 6-key freeze |
| Eager scraper capture at boot | Lazy Closure registration (constraint) + existing mock-based tests fail loudly if violated |
| Regression to copy-paste path | `PlatformControllerConvergenceTest` |
| Audit pipeline auditing dead concepts | Lens prose refresh + `AuditPipelineIntegrityTest` |
