# Phase 4 — Listen sourcing: tracks, and the retirement of `channel`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land real `track` items on dev from YouTube Music (free) plus Spotify and SoundCloud (Apify actors), prove cross-platform dedup through the Phase 2 identity keys, and retire the `channel` kind with its last producers.

**Architecture:** The youtube_music lane is already built and registered — it needs a live connection, not code. Spotify and SoundCloud keep their `source_key` but have their connector bodies **rewritten in place**, from keyless one-entity oEmbed (`target: 'channel'`, `CostClass::Free`) to Apify actor runs producing track lists (`target: 'track'`, `CostClass::Paid`). The paid call goes through the existing `$io->effect('actor', 'music', …)` seam, which requires one new `BilledEffectDriver` — the same class of work slice 4 found missing for menus. Dedup needs no Phase 2 change: `IdentityKeyDeriver` already derives `Isrc` from `f_catalog.isrc` and `TitleRelease` from headline + `f_authored.creator`; the projectors simply have to emit them.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, Postgres (Supabase dev `glncumufgaqcmqhzwrxm`), Apify (`run-sync-get-dataset-items`), Laravel Cloud CLI.

**Spec:** `docs/2026-08-14-convergence-phases.md` §4 and `docs/convergence-log.md` F9/F10. Session prompt: `docs/superpowers/plans/2026-08-14-convergence-session-prompts.md` §5.

---

## Global Constraints

- **Dev only. Production is out of scope and no tool call may name it.**
- **Apify spend ceiling for this phase: US$5**, inside the programme's US$18 cap. Owner confirms remaining credit before the first paid run. Exceeding the ceiling is a STOP, not a spend.
- **Do NOT narrow the `content.items.kind` / `content.source_items.kind` DB CHECK** (convergence-log F9). The registry is deliberately narrower than the DB.
- Every cache key carries a TTL; never `Cache::forever()`.
- New tests live in `tests/Feature/Content/`, `tests/Feature/Ingest/` or `tests/Unit/` — a NEW `tests/Feature` child directory fails `AuditPipelineIntegrityTest` unless wired into `codebase_chunks()`.
- Touching `app/Ingest/Projection/ProjectionWriter.php` means running `composer test:pg`, not just the SQLite suite.
- Outbound HTTP: the `Http` facade only. The Apify call is category **A (ConstantEndpoint)** — host is a config value.
- Branch `feat/phase-4-listen`, worktree `.worktrees/phase-4-listen`, based on `origin/development` @ `e6281faa8`.

## Entry-gate facts (re-derived live on dev 2026-08-16, not cited from a checkpoint)

| Fact | Value |
|---|---|
| `content.items` kind=`track` | **0** |
| `content.items` kind=`channel` | **9** (spotify 4, twitch 4, soundcloud 1) |
| `content.f_channel` rows | **9** |
| `ingest.sources` for `youtube_music` | **0** (none provisioned) |
| `site.platform_connections` `youtube_music.channel` | 1 row, **soft-deleted**, `channelId=UC3AXBjLrXTrTpm4SwYdBYAQ` (King Gizzard Topic), account `ollies` |
| Live `spotify.player` connections | 3 |
| Live `soundcloud.player` connections | 2 |
| `ingest.sources` spotify / soundcloud | 4 (3 auto_sync) / 1 (1 auto_sync) |

Twitch's 4 `channel` items are already orphaned — Phase 1 de-sourced the platform and no Twitch connector remains in `app/Ingest/Connectors/`.

## File Structure

| File | Responsibility |
|---|---|
| `app/Ingest/Runtime/Effects/MusicActorDriver.php` | **Create.** `('actor', 'music')` → runs the per-platform Apify actor, claims `ApifyBudget`, returns the dataset rows. |
| `app/Services/Platforms/Actors/MusicActorAdapter.php` | **Create.** Interface: `input(string $identifier): array`, `tracks(array $dataset): array`. |
| `app/Services/Platforms/Actors/SpotifyTracksAdapter.php` | **Create.** Maps a Spotify artist URL → actor input; dataset rows → normalized tracks. |
| `app/Services/Platforms/Actors/SoundcloudTracksAdapter.php` | **Create.** Same for SoundCloud (`mode: userUrl`). |
| `app/Providers/AppServiceProvider.php:123-126` | **Modify.** Register `MusicActorDriver` in `BilledEffectDriverRegistry`. |
| `config/partna.php` | **Modify.** Add `partna.music.platforms` — actor id, adapter class, max tracks, per-platform daily cap. |
| `app/Ingest/Connectors/SpotifyOembedConnector.php` | **Rewrite in place** → `SpotifyTracksConnector`, `target: 'track'`, `CostClass::Paid`. |
| `app/Ingest/Connectors/SoundcloudConnector.php` | **Rewrite in place** → track stream, `CostClass::Paid`. |
| `app/Ingest/Projection/SpotifyTrackProjector.php` | **Create.** Emits `f_catalog.isrc`, `f_authored.creator`, `f_duration.seconds`. |
| `app/Ingest/Projection/SoundcloudTrackProjector.php` | **Create.** Same shape. |
| `app/Ingest/Projection/SpotifyChannelProjector.php` | **Delete** (Task 8). |
| `app/Ingest/Projection/SoundcloudChannelProjector.php` | **Delete** (Task 8). |
| `app/Ingest/ConnectorRegistry.php` | **Modify.** Rename class references. |
| `app/Ingest/Projection/ProjectorRegistry.php` | **Modify.** Repoint spotify/soundcloud to the track projectors. |
| `app/Services/Content/KindRegistry.php:60-61` | **Modify.** Remove the `channel` entry. |
| `app/Console/Commands/ContentRetireChannelKindCommand.php` | **Create.** Deletes the 9 orphan `channel` items + `f_channel` rows, idempotent, `--dry-run` default. |

---

## Task 1: Restore the youtube_music connection and land the first `track` rows

Free lane, no Apify. This proves connector → projector → `content.items` end to end before any money is involved. Owner approved restoring the soft-deleted connection (2026-08-16).

**Files:**
- Test: `tests/Feature/Ingest/YoutubeMusicProvisioningTest.php` (create)

**Interfaces:**
- Consumes: `App\Ingest\SourceProvisioner::sync(IntegrationConnection $c): array{status: string, source_key?: string, reason?: string}`
- Produces: nothing code-side; dev data only.

- [ ] **Step 1: Write the failing test**

Pins the actual reason the gate was blocked — a trashed connection provisions nothing, a restored one provisions a source. Guards the restore against a future soft-delete silently re-emptying the lane.

```php
<?php

use App\Ingest\SourceProvisioner;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;

it('never provisions a source for a trashed youtube_music connection', function () {
    $user = User::factory()->create();
    $connection = IntegrationConnection::factory()->create([
        'user_id' => $user->id,
        'surface_key' => 'youtube_music.channel',
        'resource_kind' => null,
        'is_active' => true,
        'payload' => ['channelId' => 'UC3AXBjLrXTrTpm4SwYdBYAQ'],
    ]);
    $connection->delete();

    $result = app(SourceProvisioner::class)->sync($connection->fresh());

    expect($result['status'])->toBe('retired');
    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->count())->toBe(0);
});

it('provisions a free auto-syncing source for a live youtube_music connection', function () {
    $user = User::factory()->create();
    $connection = IntegrationConnection::factory()->create([
        'user_id' => $user->id,
        'surface_key' => 'youtube_music.channel',
        'resource_kind' => null,
        'is_active' => true,
        'payload' => ['channelId' => 'UC3AXBjLrXTrTpm4SwYdBYAQ'],
    ]);

    $result = app(SourceProvisioner::class)->sync($connection);

    expect($result['status'])->toBe('created');
    expect($result['source_key'])->toBe('youtube_music');

    $source = DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    expect($source->identifier)->toBe('UC3AXBjLrXTrTpm4SwYdBYAQ');
    // youtube_music is CostClass::Free, so schedulable() lets the dispatcher have it.
    expect((bool) $source->auto_sync)->toBeTrue();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/YoutubeMusicProvisioningTest.php`
Expected: FAIL — the factory may not support `surface_key`/`payload` as written. Fix the *test* to match the real factory; do NOT change `SourceProvisioner` to suit it. The production behaviour under test already exists and must pass unmodified.

- [ ] **Step 3: Make it pass**

No production change expected. If the second test fails on identifier derivation, re-read `SourceProvisioner::identifierFor()`'s `youtube_music` arm — it accepts `payload.channelId` or a `resource_id` matching `/^UC[A-Za-z0-9_-]{22}$/` and nothing else.

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/YoutubeMusicProvisioningTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Restore the connection on dev**

One row, by id, with the change scoped so it cannot touch anything else. Record the before/after count.

```sql
-- before
select id, deleted_at from site.platform_connections
where id = '019fa6f3-7d49-72c9-9de0-299883429cf1';

update site.platform_connections
set deleted_at = null, updated_at = now()
where id = '019fa6f3-7d49-72c9-9de0-299883429cf1' and deleted_at is not null;
-- expect: UPDATE 1
```

**This is a raw SQL write that bypasses Eloquent**, so no observer fires. Provisioning is done explicitly in the next step rather than relied on.

- [ ] **Step 6: Provision, dispatch, project on dev**

```bash
cloud command:run partna development "ingest:backfill-sources --connector=youtube_music"
cloud command:run partna development "ingest:dispatch --source-key=youtube_music"
cloud command:run partna development "ingest:project --source-key=youtube_music"
```

Check each command's real flag names with `--help` first; `--connector` was added by slice 4 and the other two may differ.

- [ ] **Step 7: Verify live**

```sql
select count(*) from content.items where kind = 'track';           -- expect > 0
select count(*) from ingest.sources where source_key='youtube_music'; -- expect 1
select i.headline_cache, i.facets_cache->'f_authored'->>'creator' as artist
from content.items i where i.kind='track' limit 10;
```

Gate: `track` count > 0 and the artist reads "King Gizzard & The Lizard Wizard" (the connector strips the `- Topic` suffix).

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/Ingest/YoutubeMusicProvisioningTest.php
git commit -m "test(ingest): a trashed connection provisions nothing, a live one provisions free"
```

---

## Task 2: Probe both Spotify actors and decide on evidence

Owner ruled: probe cheaply, then pick — documentation omitting ISRC does not prove the actor omits it. **Ships no code.** `APIFY_TOKEN` exists only on the Cloud envs, so this runs on dev.

**Files:** none (throwaway tinker).

- [ ] **Step 1: Confirm remaining Apify credit with the owner**

STOP here until the owner reports the figure. The programme rule forbids citing slice 4's "$2.81 of $29" as evidence. If remaining credit is under US$5, that is a STOP, not a smaller spend.

- [ ] **Step 2: Probe the URL-anchored candidate**

```bash
cloud tinker partna development
```

```php
$r = Http::timeout(120)->post(
    'https://api.apify.com/v2/acts/automation-lab~spotify-scraper/run-sync-get-dataset-items',
    ['mode' => 'urls', 'urls' => ['https://open.spotify.com/artist/0OdUWJ0sBjDrqHygGUXeCF'], 'maxResults' => 3]
    + ['token' => config('services.apify.token')]
);
// Field NAMES are what matters, not the values.
collect($r->json())->take(2)->map(fn ($row) => array_keys($row));
```

Record: does any row carry `isrc` (or a nested `external_ids.isrc`)? Also record `name`, duration, release date and track-URL field names — Task 4 maps them verbatim.

- [ ] **Step 3: Probe the ISRC-documented candidate**

```php
$r = Http::timeout(120)->post(
    'https://api.apify.com/v2/acts/hipersoft~spotify-scraper/run-sync-get-dataset-items',
    ['searches' => ['Band of Horses'], 'searchType' => ['track'], 'maxItemsPerSearch' => 3]
    + ['token' => config('services.apify.token')]
);
collect($r->json())->take(2)->map(fn ($row) => array_keys($row));
```

- [ ] **Step 4: Decide and write it down**

Decision rule, in priority order:
1. If `automation-lab` returns ISRC → **choose it**. Identity-anchored *and* joining-tier: no trade-off left.
2. If only `hipersoft` returns ISRC → **still choose `automation-lab`**. A keyword search cannot be anchored to the connection's artist URL, and `SourceProvisioner`'s own docblock rules that a wrong identifier fetching somebody else's catalogue is worse than no row. Accept `TitleRelease` dedup for Spotify; SoundCloud still supplies ISRC, so the Phase 2 joining key is exercised.
3. If neither returns ISRC → choose `automation-lab` and record in the checkpoint that F10's ISRC question stays open for Spotify.

Append the chosen actor id, the observed field names, and the actual cost to `docs/convergence-log.md` as finding **F29**.

- [ ] **Step 5: Commit the finding**

```bash
git add docs/convergence-log.md
git commit -m "docs(convergence): F29 — the Spotify actor decided on probe evidence"
```

---

## Task 3: `MusicActorDriver` — the billed-effect driver Phase 5 found missing

Without this, a connector declaring `$io->effect('actor', 'music', …)` dies in `HttpIo::runBilledEffect()` with "No billed-effect driver is wired" — exactly the wall slice 4 hit. Modelled on `InstagramActorDriver`, whose ordering rules are load-bearing and reproduced deliberately.

**Files:**
- Create: `app/Services/Platforms/Actors/MusicActorAdapter.php`
- Create: `app/Services/Platforms/Actors/SpotifyTracksAdapter.php`
- Create: `app/Services/Platforms/Actors/SoundcloudTracksAdapter.php`
- Create: `app/Ingest/Runtime/Effects/MusicActorDriver.php`
- Modify: `app/Providers/AppServiceProvider.php:123-126`
- Modify: `config/partna.php` (new `music` block)
- Test: `tests/Feature/Ingest/MusicActorDriverTest.php`

**Interfaces:**
- Consumes: `BilledEffectContext{kind,name,input,runId,sourceId,userId}`, `App\Services\Cache\ApifyBudget::tryClaim(string $actorTag): bool`
- Produces:
  - `MusicActorAdapter::input(string $identifier, int $maxTracks): array<string,mixed>`
  - `MusicActorAdapter::tracks(array $dataset): list<array<string,mixed>>` — normalized rows with keys `external_id, title, url, artist, isrc, duration_seconds, published, artwork`
  - `MusicActorDriver::supports('actor', 'music'): true`
  - The effect input contract: `['platform' => 'spotify'|'soundcloud', 'identifier' => string]`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\MusicActorDriver;
use App\Ingest\Runtime\EffectNotAttempted;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.music.platforms.spotify.actor', 'automation-lab~spotify-scraper');
    config()->set('partna.music.platforms.spotify.max_tracks', 50);
});

function musicCtx(array $input = []): BilledEffectContext
{
    return new BilledEffectContext(
        kind: 'actor', name: 'music',
        input: $input + ['platform' => 'spotify', 'identifier' => 'https://open.spotify.com/artist/abc'],
        runId: 'run-1', sourceId: 'src-1', userId: 'user-1',
    );
}

it('supports only the music actor effect', function () {
    $driver = app(MusicActorDriver::class);
    expect($driver->supports('actor', 'music'))->toBeTrue();
    expect($driver->supports('actor', 'instagram'))->toBeFalse();
    expect($driver->supports('api', 'music'))->toBeFalse();
});

it('returns normalized tracks from the actor dataset', function () {
    Http::fake(['api.apify.com/*' => Http::response([
        ['name' => 'The Funeral', 'url' => 'https://open.spotify.com/track/t1',
         'id' => 't1', 'artists' => ['Band of Horses'], 'durationMs' => 321000,
         'isrc' => 'USUM71234567', 'releaseDate' => '2006-03-21'],
    ], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    expect($result->data)->toHaveCount(1);
    expect($result->data[0]['isrc'])->toBe('USUM71234567');
    expect($result->data[0]['duration_seconds'])->toBe(321);
    expect($result->data[0]['title'])->toBe('The Funeral');
});

it('refuses to attempt when the token is missing, so the ledger claim is released', function () {
    config()->set('services.apify.token', null);

    expect(fn () => app(MusicActorDriver::class)->run(musicCtx()))
        ->toThrow(EffectNotAttempted::class);
});

it('refuses to attempt an unconfigured platform rather than spending', function () {
    expect(fn () => app(MusicActorDriver::class)->run(musicCtx(['platform' => 'bandcamp'])))
        ->toThrow(EffectNotAttempted::class);
});

it('answers with no data when the actor returns an empty dataset', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    // An artist with no public tracks is an ANSWER, not an outage — landing
    // nothing must not tombstone a healthy catalogue.
    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    expect($result->data)->toBe([]);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/MusicActorDriverTest.php`
Expected: FAIL — `Class "App\Ingest\Runtime\Effects\MusicActorDriver" does not exist`.

- [ ] **Step 3: Write the adapter interface**

```php
<?php

namespace App\Services\Platforms\Actors;

/**
 * One music platform's view of "run an actor and get tracks back". The driver
 * owns budget, token and transport; an adapter owns ONLY the vendor's input
 * shape and the field names its dataset happens to use — which differ per
 * actor and were pinned by live probe (convergence-log F29), not by docs.
 */
interface MusicActorAdapter
{
    /** @return array<string, mixed> */
    public function input(string $identifier, int $maxTracks): array;

    /**
     * @param  list<array<string, mixed>>  $dataset
     * @return list<array{external_id: string, title: string, url: string, artist: ?string, isrc: ?string, duration_seconds: ?int, published: ?string, artwork: ?string}>
     */
    public function tracks(array $dataset): array;
}
```

- [ ] **Step 4: Write the Spotify adapter**

Field names below are the probe's *expected* shape — Task 2 Step 4 may correct them. If it does, change them HERE and nowhere else.

```php
<?php

namespace App\Services\Platforms\Actors;

class SpotifyTracksAdapter implements MusicActorAdapter
{
    public function input(string $identifier, int $maxTracks): array
    {
        // URL mode, not search: the identifier IS the connection's artist URL,
        // so the actor can never resolve to a different artist of the same name.
        return ['mode' => 'urls', 'urls' => [$identifier], 'maxResults' => $maxTracks];
    }

    public function tracks(array $dataset): array
    {
        $out = [];
        foreach ($dataset as $row) {
            $title = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $url = is_string($row['url'] ?? null) ? $row['url'] : '';
            if ($title === '' || $url === '') {
                continue;
            }

            $artists = $row['artists'] ?? null;
            $ms = $row['durationMs'] ?? null;

            $out[] = [
                'external_id' => (string) ($row['id'] ?? $url),
                'title' => $title,
                'url' => $url,
                'artist' => is_array($artists) ? (is_string($artists[0] ?? null) ? $artists[0] : null) : (is_string($artists) ? $artists : null),
                // Nested under external_ids on some actor builds; both read here
                // so a build change degrades to null rather than a wrong code.
                'isrc' => $this->isrc($row),
                'duration_seconds' => is_numeric($ms) ? (int) round($ms / 1000) : null,
                'published' => is_string($row['releaseDate'] ?? null) ? $row['releaseDate'] : null,
                'artwork' => is_string($row['coverUrl'] ?? null) ? $row['coverUrl'] : null,
            ];
        }

        return $out;
    }

    private function isrc(array $row): ?string
    {
        $isrc = $row['isrc'] ?? ($row['external_ids']['isrc'] ?? null);

        return is_string($isrc) && trim($isrc) !== '' ? strtoupper(trim($isrc)) : null;
    }
}
```

- [ ] **Step 5: Write the SoundCloud adapter**

```php
<?php

namespace App\Services\Platforms\Actors;

class SoundcloudTracksAdapter implements MusicActorAdapter
{
    public function input(string $identifier, int $maxTracks): array
    {
        // userUrl mode: the identifier is the profile permalink the connection
        // stores, so the actor walks THAT artist's uploads and nobody else's.
        return [
            'mode' => 'userUrl',
            'startUrls' => [['url' => $identifier]],
            'maxResults' => $maxTracks,
            'includeUserDetails' => true,
        ];
    }

    public function tracks(array $dataset): array
    {
        $out = [];
        foreach ($dataset as $row) {
            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
            $url = is_string($row['url'] ?? null) ? $row['url'] : '';
            if ($title === '' || $url === '') {
                continue;
            }

            $ms = $row['duration'] ?? null;
            $isrc = $row['isrc'] ?? null;
            $user = $row['user'] ?? null;

            $out[] = [
                'external_id' => (string) ($row['id'] ?? $url),
                'title' => $title,
                'url' => $url,
                'artist' => is_array($user) && is_string($user['username'] ?? null) ? $user['username'] : null,
                'isrc' => is_string($isrc) && trim($isrc) !== '' ? strtoupper(trim($isrc)) : null,
                'duration_seconds' => is_numeric($ms) ? (int) round($ms / 1000) : null,
                'published' => is_string($row['releaseDate'] ?? null) ? $row['releaseDate'] : null,
                'artwork' => is_string($row['artworkUrl'] ?? null) ? $row['artworkUrl'] : null,
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 6: Write the driver**

```php
<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\Actors\MusicActorAdapter;
use Illuminate\Support\Facades\Http;

/**
 * ('actor', 'music') — the paid Apify track scrape for Spotify and SoundCloud.
 *
 * ORDERING IS LOAD-BEARING, copied from InstagramActorDriver for the same
 * reason: every check that can refuse the run happens BEFORE the budget claim,
 * so a config fault cannot drain the daily cap doing nothing. Both refusals
 * throw EffectNotAttempted, which releases the ledger claim rather than
 * leaving the source locked for the freshness window.
 *
 * An empty dataset is an ANSWER, not an outage: an artist with no public
 * tracks must settle ok rather than re-bill on every run.
 */
final class MusicActorDriver implements BilledEffectDriver
{
    public function __construct(private readonly ApifyBudget $budget) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'actor' && $name === 'music';
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $platform = (string) ($ctx->input['platform'] ?? '');
        $identifier = trim((string) ($ctx->input['identifier'] ?? ''));

        if ($identifier === '') {
            return BilledEffectResult::noAnswer('music actor effect carried no identifier');
        }

        $config = config("partna.music.platforms.{$platform}");
        $token = config('services.apify.token');

        if (! is_array($config) || ! is_string($config['actor'] ?? null) || ! $token) {
            throw new EffectNotAttempted("no Apify actor or token configured for music platform '{$platform}'");
        }

        /** @var MusicActorAdapter $adapter */
        $adapter = app($config['adapter']);

        if (! $this->budget->tryClaim("music-{$platform}")) {
            throw new EffectNotAttempted("Apify daily cap reached for actor 'music-{$platform}'");
        }

        $response = Http::timeout((int) config('partna.apify.run_sync_timeout_seconds'))
            ->post(
                'https://api.apify.com/v2/acts/'.$config['actor'].'/run-sync-get-dataset-items',
                $adapter->input($identifier, (int) $config['max_tracks']) + ['token' => $token],
            );

        if (! $response->successful()) {
            return BilledEffectResult::noAnswer("music actor '{$platform}' returned {$response->status()}");
        }

        $dataset = $response->json();

        return BilledEffectResult::answered($adapter->tracks(is_array($dataset) ? $dataset : []));
    }
}
```

- [ ] **Step 7: Add the config block**

In `config/partna.php`, beside the existing `apify` block:

```php
// Listen sourcing (convergence Phase 4). Actor ids pinned by live probe
// (convergence-log F29), never by an actor's marketing copy — the Spotify
// candidate that DOCUMENTS isrc takes keyword searches only, which cannot be
// anchored to a connection's artist URL.
'music' => [
    'platforms' => [
        'spotify' => [
            'actor' => env('PARTNA_SPOTIFY_ACTOR', 'automation-lab~spotify-scraper'),
            'adapter' => \App\Services\Platforms\Actors\SpotifyTracksAdapter::class,
            'max_tracks' => (int) env('PARTNA_SPOTIFY_MAX_TRACKS', 50),
        ],
        'soundcloud' => [
            'actor' => env('PARTNA_SOUNDCLOUD_ACTOR', 'automation-lab~soundcloud-scraper'),
            'adapter' => \App\Services\Platforms\Actors\SoundcloudTracksAdapter::class,
            'max_tracks' => (int) env('PARTNA_SOUNDCLOUD_MAX_TRACKS', 50),
        ],
    ],
],
```

Add the matching per-actor daily caps to `partna.apify.per_actor` (`music-spotify`, `music-soundcloud`) so `ApifyBudget::tryClaim()` has a ceiling to read. Confirm the exact key name by reading `app/Services/Cache/ApifyBudget.php` before writing it.

- [ ] **Step 8: Register the driver**

`app/Providers/AppServiceProvider.php`, in the `BilledEffectDriverRegistry` singleton:

```php
$this->app->singleton(BilledEffectDriverRegistry::class, fn ($app) => new BilledEffectDriverRegistry([
    $app->make(PlacesDetailsDriver::class),
    $app->make(InstagramActorDriver::class),
    $app->make(MusicActorDriver::class),
]));
```

Delete the stale half of that class's docblock claiming the registry holds only two drivers, and correct `BilledEffectDriverRegistry`'s own comment about menu connectors having no driver if it now misleads.

- [ ] **Step 9: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/MusicActorDriverTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 10: Commit**

```bash
git add app/Ingest/Runtime/Effects/MusicActorDriver.php app/Services/Platforms/Actors/ app/Providers/AppServiceProvider.php config/partna.php tests/Feature/Ingest/MusicActorDriverTest.php
git commit -m "feat(ingest): a billed-effect driver for the music actors"
```

---

## Task 4: `SpotifyTracksConnector` — the oEmbed connector becomes a track producer

Rewritten in place: the `source_key` must stay `spotify` because 4 `ingest.sources` rows and every `spotify.player` surface key derive from it.

**Files:**
- Modify (rewrite): `app/Ingest/Connectors/SpotifyOembedConnector.php` → rename class + file to `SpotifyTracksConnector.php`
- Modify: `app/Ingest/ConnectorRegistry.php`
- Create: `app/Ingest/Projection/SpotifyTrackProjector.php`
- Modify: `app/Ingest/Projection/ProjectorRegistry.php`
- Test: `tests/Feature/Ingest/SpotifyTracksConnectorTest.php`

**Interfaces:**
- Consumes: `MusicActorDriver` via `$io->effect('actor', 'music', ['platform' => 'spotify', 'identifier' => $pull->identifier])`
- Produces: stream `tracks` (`target: 'track'`, `SourceProfile::Catalogue`, `orderField: 'published'`), record docs with the adapter's normalized keys; `SpotifyTrackProjector::kind() === 'track'`, `version() === 1`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ingest\Connectors\SpotifyTracksConnector;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Projection\SpotifyTrackProjector;

it('declares a paid track stream, never a free channel one', function () {
    $manifest = SpotifyTracksConnector::manifest();

    expect($manifest->cost)->toBe(CostClass::Paid);
    expect($manifest->streams)->toHaveKey('tracks');
    expect($manifest->streams['tracks']->target)->toBe('track');
    expect(array_keys($manifest->streams))->not->toContain('listen');
});

it('yields one record per track the effect returns', function () {
    $io = fakeIoReturningEffect([
        ['external_id' => 't1', 'title' => 'The Funeral', 'url' => 'https://open.spotify.com/track/t1',
         'artist' => 'Band of Horses', 'isrc' => 'USUM71234567', 'duration_seconds' => 321,
         'published' => '2006-03-21', 'artwork' => 'https://i.scdn.co/x.jpg'],
    ]);

    $messages = iterator_to_array((new SpotifyTracksConnector)->pull(
        fakePull('https://open.spotify.com/artist/abc'), $io
    ));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    expect($records)->toHaveCount(1);
    expect($records[0]->key)->toBe('t1');
    expect(array_filter($messages, fn ($m) => $m instanceof Covered))->not->toBeEmpty();
});

it('emits a Note and claims no coverage when the artist has no tracks', function () {
    $messages = iterator_to_array((new SpotifyTracksConnector)->pull(
        fakePull('https://open.spotify.com/artist/abc'), fakeIoReturningEffect([])
    ));

    // Same guard as YoutubeMusicConnector's empty feed: landing nothing must
    // never read as "the artist deleted their catalogue" and tombstone it.
    expect(array_filter($messages, fn ($m) => $m instanceof Note))->not->toBeEmpty();
    expect(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('projects a track carrying the isrc and artist the identity keys need', function () {
    $projection = (new SpotifyTrackProjector)->project(recordView([
        'external_id' => 't1', 'title' => 'The Funeral', 'url' => 'https://open.spotify.com/track/t1',
        'artist' => 'Band of Horses', 'isrc' => 'USUM71234567', 'duration_seconds' => 321,
        'published' => '2006-03-21', 'artwork' => 'https://i.scdn.co/x.jpg',
    ]));

    expect($projection['kind'])->toBe('track');
    expect($projection['headline'])->toBe('The Funeral');
    expect($projection['facets']['f_catalog']['isrc'])->toBe('USUM71234567');
    expect($projection['facets']['f_authored']['creator'])->toBe('Band of Horses');
    expect($projection['facets']['f_duration']['seconds'])->toBe(321);
});
```

`fakeIoReturningEffect`, `fakePull` and `recordView` must live in **this test file** or in an already-loaded shared helper file — a helper defined in one test file and used from another is fatal under `--parallel`. Copy the existing helper shape from `tests/Feature/Ingest/` rather than inventing one; check how `MenuItemProjector`'s tests build a `RecordView` and reuse that exactly.

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/SpotifyTracksConnectorTest.php`
Expected: FAIL — `SpotifyTracksConnector` does not exist.

- [ ] **Step 3: Rewrite the connector**

```php
<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * An artist's Spotify catalogue, via a paid Apify actor (convergence Phase 4).
 *
 * This class REPLACED a keyless oEmbed connector that resolved one entity to
 * the `channel` kind. oEmbed cannot list anything — it answers about the embed
 * itself — so it could never produce tracks, which is why the `channel` kind
 * existed at all and why retiring it required this connector first.
 *
 * The identifier is the connection's own artist URL and the actor runs in URL
 * mode, so it can never resolve to a different artist who shares a name.
 */
class SpotifyTracksConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('spotify'),
            identifierKind: 'url',
            hosts: ['open.spotify.com', 'spotify.com', '*.spotifycdn.com', '*.scdn.co', 'api.apify.com'],
            streams: [
                'tracks' => new StreamSpec(
                    name: 'tracks',
                    target: 'track',
                    profile: SourceProfile::Catalogue,
                    requires: ['title', 'url'],
                    volatile: [],
                    orderField: 'published',
                ),
            ],
            cost: CostClass::Paid,
            defaultIntervalSeconds: 604800,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $effect = $io->effect('actor', 'music', [
            'platform' => 'spotify',
            'identifier' => trim($pull->identifier),
        ]);

        if ($effect['status'] !== 'ok') {
            yield new Unavailable("music actor effect was {$effect['status']}");

            return;
        }

        $tracks = is_array($effect['data']) ? $effect['data'] : [];

        if ($tracks === []) {
            // No coverage claim: an artist whose catalogue the actor could not
            // see must never tombstone tracks that landed on a healthier run.
            yield new Note('empty_catalogue', 'The actor returned no tracks for this artist');

            return;
        }

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $tracks = array_slice($tracks, 0, $limit);
        }

        foreach ($tracks as $track) {
            yield new Record('tracks', (string) $track['external_id'], $track);
        }

        $dates = array_filter(array_column($tracks, 'published'));
        yield new Covered('tracks', Coverage::prefix($dates === [] ? null : min($dates), count($tracks)));
    }
}
```

Delete `app/Ingest/Connectors/SpotifyOembedConnector.php` and update the `ConnectorRegistry` import and `'spotify' => …` line to `SpotifyTracksConnector::class`.

- [ ] **Step 4: Write the track projector**

```php
<?php

namespace App\Ingest\Projection;

/**
 * A Spotify actor track → the `track` kind.
 *
 * f_catalog.isrc is the point of this projector as much as the display fields
 * are: IdentityKeyDeriver already derives the Isrc joining key from that
 * column and has simply had no producer (convergence-log F10). Emitting it
 * here is what turns cross-platform track dedup from title-matching into a
 * vendor-identifier match.
 */
class SpotifyTrackProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'track';
    }

    public function project(RecordView $view): ?array
    {
        $title = $view->string('title');
        $url = $view->string('url');
        if ($title === null || $url === null) {
            return null;
        }

        $seconds = $view->int('duration_seconds');

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_catalog' => $view->string('isrc') === null ? null : ['isrc' => $view->string('isrc')],
                'f_authored' => $view->string('artist') === null ? null : ['creator' => $view->string('artist')],
                'f_duration' => $seconds === null || $seconds <= 0 ? null : ['seconds' => $seconds],
                'f_published' => $view->string('published') === null ? null : ['published_from' => $view->string('published')],
                'f_embed' => ['provider' => 'spotify', 'embed_key' => ltrim((string) parse_url($url, PHP_URL_PATH), '/')],
            ]),
            'media' => $view->string('artwork') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('artwork')],
            ],
        ];
    }
}
```

Confirm `RecordView::int()` exists; if it does not, read the value with `string()` and cast, matching how a sibling projector handles a numeric field.

Repoint `ProjectorRegistry`: `'spotify' => ['tracks' => SpotifyTrackProjector::class]`.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/SpotifyTracksConnectorTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Run the registry guards**

Run: `./vendor/bin/pest tests/Feature/Ingest tests/Unit --filter=Registry`
Expected: PASS. `ConnectorRegistryTest` walks `app/Ingest/Connectors` and fails on any class missing from the map or registered under the wrong key.

- [ ] **Step 7: Commit**

```bash
git add app/Ingest/Connectors app/Ingest/Projection app/Ingest/ConnectorRegistry.php tests/Feature/Ingest/SpotifyTracksConnectorTest.php
git commit -m "feat(ingest): spotify produces tracks through the music actor"
```

---

## Task 5: `SoundcloudTracksConnector` — the same shape for SoundCloud

**Files:**
- Modify (rewrite): `app/Ingest/Connectors/SoundcloudConnector.php`
- Create: `app/Ingest/Projection/SoundcloudTrackProjector.php`
- Modify: `app/Ingest/ConnectorRegistry.php`, `app/Ingest/Projection/ProjectorRegistry.php`
- Test: `tests/Feature/Ingest/SoundcloudTracksConnectorTest.php`

**Interfaces:**
- Consumes: `$io->effect('actor', 'music', ['platform' => 'soundcloud', 'identifier' => $pull->identifier])`
- Produces: stream `tracks` (`target: 'track'`), `SoundcloudTrackProjector::kind() === 'track'`, `version() === 1`

- [ ] **Step 1: Write the failing test**

Mirror Task 4 Step 1 exactly, substituting `SoundcloudTracksConnector`, `SoundcloudTrackProjector`, identifier `https://soundcloud.com/flume`, and a record shaped:

```php
['external_id' => 's1', 'title' => 'Never Be Like You', 'url' => 'https://soundcloud.com/flume/never-be-like-you',
 'artist' => 'Flume', 'isrc' => 'AUUM71600001', 'duration_seconds' => 236,
 'published' => '2016-01-21', 'artwork' => 'https://i1.sndcdn.com/x.jpg']
```

Assert the same four behaviours: paid track stream, one record per track, Note-without-Coverage on empty, and a projection carrying `f_catalog.isrc` + `f_authored.creator`.

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/SoundcloudTracksConnectorTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the connector**

Copy Task 4 Step 3's connector verbatim with these changes: class `SoundcloudTracksConnector`, `SourceKey::of('soundcloud')`, `'platform' => 'soundcloud'`, hosts `['soundcloud.com', 'www.soundcloud.com', 'w.soundcloud.com', '*.sndcdn.com', 'api.apify.com']`.

- [ ] **Step 4: Write the projector**

Copy Task 4 Step 4's projector with class `SoundcloudTrackProjector` and the embed facet built from the widget URL rather than a path key:

```php
'f_embed' => ['provider' => 'soundcloud', 'embed_key' => $url],
```

Repoint `ProjectorRegistry`: `'soundcloud' => ['tracks' => SoundcloudTrackProjector::class]`.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/SoundcloudTracksConnectorTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Ingest tests/Feature/Ingest/SoundcloudTracksConnectorTest.php
git commit -m "feat(ingest): soundcloud produces tracks through the music actor"
```

---

## Task 6: Stop the free-era sources auto-dispatching a paid connector

**A spend hazard, not housekeeping.** `SourceProvisioner::sync()` only ever turns `auto_sync` ON (line 117-119) and never off, and `cost_units` is written once at insert. Dev already carries 3 spotify + 1 soundcloud sources at `auto_sync=true` with Free's budget weight — after Tasks 4-5 those rows would auto-dispatch a **paid** actor on the scheduler's cadence, charged at the wrong weight.

**Files:**
- Test: `tests/Feature/Ingest/PaidConnectorAutoSyncTest.php` (create)

**Interfaces:**
- Consumes: `SourceProvisioner::sync()`, `Manifest::cost`, `CostClass::budgetWeight()`

- [ ] **Step 1: Write the failing test**

```php
it('never leaves a source auto-syncing a paid connector', function () {
    foreach (\App\Ingest\ConnectorRegistry::all() as $key => $class) {
        $manifest = $class::manifest();
        if ($manifest->cost === \App\Ingest\Manifest\CostClass::Free) {
            continue;
        }

        $rows = DB::table('ingest.sources')->where('source_key', $key)->where('auto_sync', true)->count();

        expect($rows)->toBe(0, "source_key '{$key}' is paid but has {$rows} auto-syncing rows");
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs the applied schema');
```

- [ ] **Step 2: Run it and watch it fail (or skip)**

Run: `./vendor/bin/pest tests/Feature/Ingest/PaidConnectorAutoSyncTest.php`
Expected: SKIP locally on SQLite. It is a live guard; the real assertion is Step 4's SQL.

- [ ] **Step 3: Correct the dev rows**

```sql
-- before
select source_key, auto_sync, cost_units, count(*)
from ingest.sources where source_key in ('spotify','soundcloud')
group by 1,2,3 order by 1;

update ingest.sources
set auto_sync = false, cost_units = 10, updated_at = now()
where source_key in ('spotify','soundcloud');
-- expect: UPDATE 5
```

Replace `10` with the real `CostClass::Paid->budgetWeight()` — read it from `app/Ingest/Manifest/CostClass.php`, do not guess. Paid sources are dispatched by hand in Task 7; auto_sync stays off, exactly as the menu connectors do.

- [ ] **Step 4: Verify**

```sql
select source_key, auto_sync, cost_units from ingest.sources
where source_key in ('spotify','soundcloud') order by 1;
-- expect: every row auto_sync=false, cost_units = the paid weight
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Ingest/PaidConnectorAutoSyncTest.php
git commit -m "test(ingest): a paid connector must never carry auto-syncing sources"
```

---

## Task 7: The live paid run — tracks from three platforms, and the merge

**Spends money.** Ceiling US$5; owner must have confirmed remaining credit in Task 2 Step 1.

- [ ] **Step 1: Re-check the cap before spending**

Confirm with the owner that credit is unchanged since Task 2. Exceeding US$5 is a STOP.

- [ ] **Step 2: Deploy the branch to dev**

Merge to `development` and push — dev deploys from `development` and live verification needs the code deployed.

```bash
git checkout development && git merge --no-ff feat/phase-4-listen && git push origin development
```

- [ ] **Step 3: Re-provision so the new manifests are reflected**

```bash
cloud command:run partna development "ingest:backfill-sources --connector=spotify"
cloud command:run partna development "ingest:backfill-sources --connector=soundcloud"
```

- [ ] **Step 4: Dispatch ONE source first**

One artist, not five — a per-track price means an unexpectedly large catalogue is the runaway risk, and `max_tracks` is the only thing bounding it.

```bash
cloud command:run partna development "ingest:dispatch --source-key=soundcloud --limit=1"
cloud command:run partna development "ingest:project --source-key=soundcloud"
```

Then check `ingest.effects` for the claim, its status and cost before dispatching anything else.

- [ ] **Step 5: Dispatch the rest**

Only if Step 4 landed rows at the expected cost. Spotify's 3 live connections and SoundCloud's remaining 1.

- [ ] **Step 6: Verify the gate live**

```sql
-- tracks exist, from more than one platform
select cs.label, count(*) from content.source_items si
join content.sources cs on cs.id = si.source_id
where si.kind='track' group by 1 order by 2 desc;

-- the ISRC joining key now has a producer
select count(*) from content.f_catalog where isrc is not null;

-- cross-platform merges
select count(*) from content.item_merges;
```

Gate: `track` rows from ≥2 platforms. If `item_merges` is 0, establish WHY before calling it a failure — dev may simply hold no track released on two of these platforms, exactly as slice 4 found for dishes. Record the reason rather than "fixing" it.

- [ ] **Step 7: Record the real spend**

Append the actual cost to convergence-log F29. If it exceeded US$5, stop and report.

---

## Task 8: Retire the `channel` kind

Last, deliberately: `channel` cannot retire while it is the only thing Spotify and SoundCloud produce.

**Files:**
- Delete: `app/Ingest/Projection/SpotifyChannelProjector.php`, `app/Ingest/Projection/SoundcloudChannelProjector.php`
- Modify: `app/Services/Content/KindRegistry.php:60-61`, `app/Ingest/Projection/ProjectorRegistry.php` (stale comment)
- Create: `app/Console/Commands/ContentRetireChannelKindCommand.php`
- Test: `tests/Feature/Content/ChannelKindRetirementTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('no longer knows the channel kind', function () {
    expect(App\Services\Content\KindRegistry::has('channel'))->toBeFalse();
    expect(App\Services\Content\KindRegistry::kinds())->not->toContain('channel');
});

it('has no connector still targeting channel', function () {
    foreach (App\Ingest\ConnectorRegistry::all() as $key => $class) {
        foreach ($class::manifest()->streams as $stream) {
            expect($stream->target)->not->toBe('channel', "{$key} still targets channel");
        }
    }
});

it('keeps the DB CHECK permissive on purpose', function () {
    // convergence-log F9: the domain is a backstop, not the source of truth.
    // Narrowing it buys nothing and forces a guard rewrite. Pinned so a later
    // reader does not "finish the job" by tightening it.
    expect(true)->toBeTrue();
})->skip('documentation-only assertion; see F9');
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/ChannelKindRetirementTest.php`
Expected: FAIL — `KindRegistry::has('channel')` is still true.

- [ ] **Step 3: Delete the projectors and the registry entry**

Remove both channel projector files, their `ProjectorRegistry` lines (already repointed in Tasks 4-5, so only the stale comment about spotify/soundcloud "still producing channel" remains — delete it), and the `'channel' => [...]` line from `KindRegistry::KINDS`.

Leave the `content.items.kind` / `content.source_items.kind` CHECK **untouched** (F9). Update `KindRegistry`'s docblock: it says "The 13 closed item kinds" and names `article` as "the first value the two sides disagree on" — after this change it is 12 kinds and `channel` is the second.

- [ ] **Step 4: Write the cleanup command**

```php
protected $signature = 'content:retire-channel-kind {--apply : actually delete}';
```

Dry-run by default. Deletes `content.f_channel` rows then `content.source_items` then `content.items` for `kind='channel'`, in FK-safe order, inside a transaction, reporting counts per table. Idempotent: a second run reports 0.

- [ ] **Step 5: Run the tests**

Run: `composer test`
Expected: PASS. Expect fallout in any test asserting a kind count of 13 or listing `channel`; fix each by updating the expectation, never by re-adding the kind.

- [ ] **Step 6: Delete the rows on dev**

```bash
cloud command:run partna development "content:retire-channel-kind"          # dry run
cloud command:run partna development "content:retire-channel-kind --apply"
```

```sql
select count(*) from content.items where kind='channel';      -- expect 0
select count(*) from content.f_channel;                        -- expect 0
```

All 9 are orphans: 4 twitch (de-sourced in Phase 1), 4 spotify + 1 soundcloud (superseded by tracks).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(content): retire the channel kind with its last producers"
```

---

## Task 9: Verify, checkpoint, merge

- [ ] **Step 1: Full suite**

Run: `composer test`
Expected: PASS. Then `composer test:pg` if `ProjectionWriter` was touched, and `composer test:schema` if any schema assertion moved.

- [ ] **Step 2: PHPStan and Pint**

Run: `./vendor/bin/phpstan analyse` and `php artisan pint`
Expected: no new errors. Annotate the model, never the call site.

- [ ] **Step 3: Write the checkpoint**

Into the parent spec `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`, following slice 4's §23 format: live SQL with its real output, the entry-gate figures re-derived, the actor decision and its evidence, the real spend, and anything left unexercised. Note explicitly whether `item_merges` moved and why.

- [ ] **Step 4: Write downstream discoveries into the prompts that act on them**

Per slice 4's rule that a checkpoint is not a communication channel. In particular, tell prompt 6 (`phase-6-pseudo-platforms`) whether `spotify`/`soundcloud` surfaces changed shape, and prompt 7 (`slice-7-teardown`) that a paid connector now exists whose sources must stay `auto_sync=false`.

- [ ] **Step 5: Merge and deploy**

```bash
git checkout development && git merge --no-ff feat/phase-4-listen && git push origin development
```

- [ ] **Step 6: Confirm dev is healthy**

```bash
cloud env:logs partna development --minutes 10
```

Check Nightwatch for new exceptions from the ingest lane.
