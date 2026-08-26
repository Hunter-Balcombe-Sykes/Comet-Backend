<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Content\ContentItemSlugAllocator;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// The projection stage end-to-end at the DB grain (plan §4→§5/§6): landed
// record versions become content.sources / source_items / items / typed
// facets, idempotently, with identity resolved by the pure Resolver rather
// than accumulated. SQLite mirrors via setupIngestTables + setupContentTables.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    // Every connector runs eagerly on connect (F21, 2026-08-18; paid ones by
    // opt-in) — under the sync test queue the observer would run the connector
    // inline and mint the stream row this file's helpers insert by hand. Keep
    // the eager run out; the projection paths under test are exercised directly.
    Bus::fake([RunSourceJob::class]);
});

// projectableBandcamp()/landCurrentRecord()/bandcampDoc() moved to tests/Helpers/ProjectionTestHelpers.php
// (CrossFileTestHelperGuardTest, 2026-08-26) — required by tests/Pest.php for every worker.

it('projects landed records into items, source items, and typed facet rows', function () {
    [$userId, $connection, $source, $streamId] = projectableBandcamp([
        'album/first' => bandcampDoc('First Album', 'https://artist.bandcamp.com/album/first'),
        'album/second' => bandcampDoc('Second Album', 'https://artist.bandcamp.com/album/second'),
    ]);

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    expect($result['status'])->toBe('ok')
        ->and($result['projected'])->toBe(2)
        ->and($result['items'])->toBe(2);

    $contentSource = DB::table('content.sources')->where('connection_id', $connection->id)->first();
    expect($contentSource)->not->toBeNull()
        ->and($contentSource->kind)->toBe('connection');

    expect(DB::table('content.source_items')->where('source_id', $contentSource->id)->count())->toBe(2)
        ->and(DB::table('content.items')->where('user_id', $userId)->where('kind', 'release')->count())->toBe(2);

    $item = DB::table('content.items')->where('user_id', $userId)->where('headline_cache', 'First Album')->first();
    expect($item)->not->toBeNull();

    expect(DB::table('content.f_link')->where('item_id', $item->id)->value('url'))->toBe('https://artist.bandcamp.com/album/first')
        ->and(DB::table('content.f_published')->where('item_id', $item->id)->value('published_from'))->toBe('2025-05-05')
        ->and(DB::table('content.f_authored')->where('item_id', $item->id)->value('creator'))->toBe('Some Artist')
        ->and(DB::table('content.f_text')->where('item_id', $item->id)->value('headline'))->toBe('First Album')
        ->and(DB::table('content.item_media')->where('item_id', $item->id)->where('role', 'cover')->count())->toBe(1)
        ->and(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBeGreaterThanOrEqual(1);

    // A Bandcamp _10 cover is 1200px by the CDN's own naming: minted with
    // DECLARED dims so best-cover can rank it against other sources.
    $asset = DB::table('content.media_assets')->where('user_id', $userId)->where('source_url', 'like', '%a1_10.jpg')->first();
    expect($asset)->not->toBeNull()
        ->and((int) $asset->width)->toBe(1200)
        ->and((int) $asset->height)->toBe(1200)
        ->and($asset->dims_confidence)->toBe('declared');
});

// Nightwatch #370: SchemaOrgEventProjector writes zone_confidence
// 'offset_only', a value content.f_occurrence's CHECK did not admit until
// 20260731230000. Every case above projects bandcamp, which never touches
// f_occurrence — so the whole suite was blind to it, and the one test that
// does mention 'offset_only' (tests/Unit/Ingest/ProjectionTest.php) asserts
// the projector's RETURN ARRAY and never reaches a database. This case exists
// to reach the write.

/** A user + active eventbrite connection + its ingest source/`events` stream, with $docs landed. */
function projectableEventbrite(array $docs, ?string $userId = null): array
{
    $userId ??= createTenant('evt-'.Str::lower(Str::random(6)))->id;

    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'eventbrite',
        'resource_id' => 'org-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => 'https://www.eventbrite.com/o/'.Str::lower(Str::random(8)).'-1234567890'],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'events',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($docs as $key => $doc) {
        landCurrentRecord($streamId, (string) $key, $doc);
    }

    return [$userId, $connection, $source, $streamId];
}

it('writes zone_confidence offset_only to content.f_occurrence for a schema.org event with a start date', function () {
    [$userId, , $source, $streamId] = projectableEventbrite([
        'e/offset' => [
            'name' => 'Laneway Session',
            'url' => 'https://www.eventbrite.com/e/laneway-session-1234567890',
            // The vendors emit the event's LOCAL offset, never an IANA zone.
            'start_date' => '2026-06-20T09:00:00+10:00',
            'end_date' => '2026-06-20T12:00:00+10:00',
            'venue' => 'The Laneway',
            'locality' => 'Melbourne',
        ],
    ]);

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'events');

    expect($result['status'])->toBe('ok')
        ->and($result['items'])->toBe(1);

    $itemId = DB::table('content.items')->where('user_id', $userId)->where('kind', 'event')->value('id');
    $occurrence = DB::table('content.f_occurrence')->where('item_id', $itemId)->first();

    expect($occurrence)->not->toBeNull()
        ->and($occurrence->zone_confidence)->toBe('offset_only')
        // timezone stays NULL by design — an offset does not name a zone, which
        // is the whole reason 'offset_only' is distinct from 'inferred'/'assumed'.
        ->and($occurrence->timezone)->toBeNull()
        ->and($occurrence->starts_at_utc)->toBe('2026-06-19T23:00:00Z');
});

it('is idempotent: re-projecting unchanged records creates nothing new', function () {
    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/one' => bandcampDoc('Only Album', 'https://artist.bandcamp.com/album/one'),
    ]);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases');
    $writer->projectStream($source, $streamId, 'releases');
    $writer->projectStream($source, $streamId, 'releases');

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->count())->toBe(1)
        ->and(DB::table('content.f_link')->count())->toBe(1)
        ->and(DB::table('content.item_media')->count())->toBe(1)
        ->and(DB::table('content.media_assets')->count())->toBe(1)
        // The replace set, pinned by CLASS rather than by count: "Only Album"
        // canonicalises to 10 characters, under TitleOnly's minimum of 12 but
        // over TitleLoose's 10 — so the pair below is also the live proof that
        // emission honours each class's own minLength.
        ->and(DB::table('content.identity_keys')->orderBy('key_class')->pluck('key_class')->all())
        ->toBe(['canonical_url', 'platform_object', 'title_loose', 'title_release']);
});

it('unions the same release across two connections into one item via CanonicalUrl', function () {
    $userId = createTenant('merge-'.Str::lower(Str::random(6)))->id;

    [, , $sourceA, $streamA] = projectableBandcamp([
        'album/shared' => bandcampDoc('Shared Album', 'https://artist.bandcamp.com/album/shared'),
    ], $userId);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'album/shared-elsewhere' => bandcampDoc('Shared Album', 'https://artist.bandcamp.com/album/shared'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    // One item, two per-source contributions, each source's facet row kept.
    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->whereNotNull('item_id')->count())->toBe(2);

    $itemId = DB::table('content.items')->where('user_id', $userId)->value('id');
    expect(DB::table('content.f_link')->where('item_id', $itemId)->count())->toBe(2);
});

it('merges an already-split pair when the joining evidence arrives later, keeping the older item', function () {
    $userId = createTenant('late-'.Str::lower(Str::random(6)))->id;

    // Two different URLs first: two items.
    [, , $sourceA, $streamA] = projectableBandcamp([
        'album/a' => bandcampDoc('Album A', 'https://a.bandcamp.com/album/a'),
    ], $userId);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'album/b' => bandcampDoc('Album B', 'https://b.bandcamp.com/album/b'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');
    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(2);

    // Source B's record changes to carry A's URL: the union appears on the
    // next projection, and the OLDER item must win the merge.
    DB::table('ingest.record_versions')->where('stream_id', $streamB)->update([
        'doc' => json_encode(bandcampDoc('Album B', 'https://a.bandcamp.com/album/a')),
    ]);
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.item_merges')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->whereNull('removed_at')->whereNotNull('item_id')->distinct()->count('item_id'))->toBe(1);
});

it('emits exactly ONE cap warning when a single run caps many evidential keys', function () {
    // #SCALE-10/#CACHE-6: the resolver caps PER KEY VALUE, so one run can cap
    // several key values at once. This pins that ProjectionWriter aggregates
    // them into a single warning, keyed off resolveItemsLocked() (not the
    // resolver, which is pure and does no I/O) — one log line per run, never
    // one per key, because a log flood is the same failure the cap exists to
    // prevent, moved to a different subsystem.
    config(['partna.ingest.max_members_per_key' => 1]);
    $userId = createTenant('cap-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    // Three "(Radio Edit)" releases, each its own connection/source so none
    // poison their own TitleLoose signature (Resolver::poisonedKeys()) and
    // none satisfy TitleRelease's cross-source corroborating union (its
    // value carries the bracketed suffix verbatim, so "Radio Edit" never
    // equals "Extended Mix" there) — only TitleLoose strips bracketed
    // content, so this is the one tier where the "Edit"/"Mix" pair matches.
    foreach ([
        ['echo/one', 'Echo Chamber (Radio Edit)', 'echo-one'],
        ['bloom/one', 'Silent Bloom (Radio Edit)', 'bloom-one'],
        ['drift/one', 'Neon Drift (Radio Edit)', 'drift-one'],
    ] as [$key, $title, $slug]) {
        [, , $source, $streamId] = projectableBandcamp([
            $key => bandcampDoc($title, "https://artist.bandcamp.com/album/{$slug}"),
        ], $userId);
        $writer->projectStream($source, $streamId, 'releases');
    }

    // The three "Extended Mix" counterparts land together on ONE new source
    // and project in a SINGLE call, so all three caps are discovered inside
    // ONE resolveItemsLocked() run — the scenario the aggregation exists for.
    [, , $sourceTwo, $streamTwo] = projectableBandcamp([
        'echo/two' => bandcampDoc('Echo Chamber (Extended Mix)', 'https://artist.bandcamp.com/album/echo-two'),
        'bloom/two' => bandcampDoc('Silent Bloom (Extended Mix)', 'https://artist.bandcamp.com/album/bloom-two'),
        'drift/two' => bandcampDoc('Neon Drift (Extended Mix)', 'https://artist.bandcamp.com/album/drift-two'),
    ], $userId);

    Log::spy();
    $writer->projectStream($sourceTwo, $streamTwo, 'releases');

    // Six distinct items, none merged — confirms the caps did their job (no
    // evidential candidate survives to pair them) without a corroborating
    // union sneaking in and making the cap count trivially right for the
    // wrong reason.
    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(6)
        ->and(DB::table('content.identity_candidates')->count())->toBe(0);

    // A real captured record, not a Mockery shouldNotReceive() — a negated
    // Mockery log assertion in this repo is known to pass vacuously.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($userId) {
            return $message === 'identity candidate cap hit — some duplicate suggestions were not recorded'
                && ($context['user_id'] ?? null) === $userId
                && ($context['kind'] ?? null) === 'release'
                && ($context['capped_key_count'] ?? null) === 3;
        });
});

it('retires source items for tombstoned records without touching the user-delete column', function () {
    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/stays' => bandcampDoc('Stays', 'https://artist.bandcamp.com/album/stays'),
        'album/goes' => bandcampDoc('Goes', 'https://artist.bandcamp.com/album/goes'),
    ]);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases');

    DB::table('ingest.record_state')->where('stream_id', $streamId)->where('key', 'album/goes')
        ->update(['tombstoned_at' => now()]);
    $writer->projectStream($source, $streamId, 'releases');

    $gone = DB::table('content.source_items')->where('record_key', 'album/goes')->first();
    expect($gone->removed_at)->not->toBeNull();

    // Availability is derived; the ITEM's removed_at is the user's alone.
    expect(DB::table('content.items')->where('user_id', $userId)->whereNotNull('removed_at')->count())->toBe(0);
});

it('folds a reappearing record back into the item the user removed, never a fresh visible one', function () {
    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/zombie' => bandcampDoc('Zombie', 'https://artist.bandcamp.com/album/zombie'),
    ]);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases');

    $itemId = DB::table('content.items')->where('user_id', $userId)->value('id');
    DB::table('content.items')->where('id', $itemId)->update(['removed_at' => now()]);

    // The record lands again (unchanged) and projection re-runs.
    $writer->projectStream($source, $streamId, 'releases');

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();
});

it('fires all three cache lanes for a projected stream, with an exact revision delta', function () {
    // Parent spec §4: a connector run that changes a rendered surface must fire
    // all three lanes. It used to fire one — BuildState only. That is not a
    // Fresha-specific concern: buildPools() renders every pool in
    // PoolRegistry::POOLS with no source-kind filter, so a scheduled YouTube,
    // Instagram, Google Business, Eventbrite or Gumroad run changes
    // payload.data.pools.* on the public profile. With only lane 1 the ORIGIN
    // kept serving its previous payload for the full 60s TTL (the cache key
    // derives from site.sites.updated_at) and the edge copy was never purged.
    //
    // EXACT delta, never toBeGreaterThan — which is what this assertion used to
    // be. A ">" bar is cleared by any neighbouring bump, so it stayed green with
    // a whole lane deleted; slice 3a shipped precisely that bug.
    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/bump' => bandcampDoc('Bump', 'https://artist.bandcamp.com/album/bump'),
    ]);
    $siteId = (string) DB::table('site.sites')->where('user_id', $userId)->value('id');

    // AFTER seeding, not before: creating the tenant and the connection fires
    // SiteObserver, which dispatches its own purge. Faking earlier makes the
    // lane-3 assertion below pass on the fixture's job rather than this run's —
    // the negative control caught exactly that.
    Queue::fake();
    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $beforeRevision = BuildState::read($siteId)['content_revision'];
    $beforeUpdatedAt = DB::table('site.sites')->where('id', $siteId)->value('updated_at');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    // Lane 1 — build state, exactly one bump for the stream.
    expect(BuildState::read($siteId)['content_revision'])->toBe($beforeRevision + 1);
    // Lane 2 — the origin's own payload cache key.
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($beforeUpdatedAt);
    // Lane 3 — the edge.
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('fires no lane at all when a stream projects nothing', function () {
    // The negative control. Without it the case above passes on an
    // implementation that busts every site on every run, which would purge the
    // edge on each of the fifteen-minute scheduled sweeps for every user.
    [$userId, , $source, $streamId] = projectableBandcamp([]);
    $siteId = (string) DB::table('site.sites')->where('user_id', $userId)->value('id');

    Queue::fake();
    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $beforeRevision = BuildState::read($siteId)['content_revision'];
    $beforeUpdatedAt = DB::table('site.sites')->where('id', $siteId)->value('updated_at');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    expect(BuildState::read($siteId)['content_revision'])->toBe($beforeRevision)
        ->and(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->toBe($beforeUpdatedAt);
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('skips streams with no projector and sources with no connection', function () {
    [, , $source, $streamId] = projectableBandcamp([]);

    $writer = app(ProjectionWriter::class);

    expect($writer->projectStream($source, $streamId, 'profile'))->toBe(['status' => 'skipped', 'reason' => 'no_projector'])
        ->and($writer->projectStream(['source_key' => 'bandcamp', 'user_id' => 'x', 'connection_id' => null], $streamId, 'releases'))
        ->toBe(['status' => 'skipped', 'reason' => 'no_connection']);
});

it('reprojects the full record log through the ingest:project command', function () {
    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/cmd' => bandcampDoc('From Command', 'https://artist.bandcamp.com/album/cmd'),
    ]);

    // Wipe derived rows to prove the command re-derives from the record log.
    DB::table('content.items')->delete();
    DB::table('content.source_items')->delete();

    $this->artisan('ingest:project', ['--user' => $userId])->assertSuccessful();

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->count())->toBe(1);
});

it('ingest:project --rebuild drops and re-derives facet rows', function () {
    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/rb' => bandcampDoc('Rebuildable', 'https://artist.bandcamp.com/album/rb'),
    ]);
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    // Poison a derived row: a rebuild must replace it with the projector's truth.
    DB::table('content.f_link')->update(['url' => 'https://wrong.example']);

    $this->artisan('ingest:project', ['--user' => $userId, '--rebuild' => true])->assertSuccessful();

    expect(DB::table('content.f_link')->value('url'))->toBe('https://artist.bandcamp.com/album/rb')
        ->and(DB::table('content.f_link')->count())->toBe(1);
});

it('ingest:project --dry-run writes nothing', function () {
    [$userId] = projectableBandcamp([
        'album/dry' => bandcampDoc('Dry', 'https://artist.bandcamp.com/album/dry'),
    ]);
    DB::table('content.items')->delete();
    DB::table('content.source_items')->delete();

    $this->artisan('ingest:project', ['--user' => $userId, '--dry-run' => true])->assertSuccessful();

    expect(DB::table('content.items')->count())->toBe(0);
});

// SCALE-1/SCALE-2: ingest:project walks ingest.sources via chunkById instead
// of a single ->get(), and pre-fetches each chunk's streams with one grouped
// query instead of one ingest.streams query per source. These four cases pin
// that walk closed: set equality (not counts — a skip+duplicate pair would
// cancel out under a count), the exact-multiple terminating page, idempotence
// across a chunk boundary, and the streams-query count itself.

/** Seed $count bandcamp sources for one user, each with a distinct doc/URL so none merge via identity resolution. */
function projectableBandcampFleet(int $count, string $userId): array
{
    $recordKeys = [];
    for ($i = 0; $i < $count; $i++) {
        $key = "album/fleet-{$i}";
        projectableBandcamp([
            $key => bandcampDoc("Fleet {$i}", "https://fleet{$i}.bandcamp.com/album/{$i}"),
        ], $userId);
        $recordKeys[] = $key;
    }

    return $recordKeys;
}

/** @return array<int, string> record_key set for a user's content.source_items, via the content.sources join (no user_id column on source_items itself). */
function projectedRecordKeys(string $userId): array
{
    return DB::table('content.source_items')
        ->join('content.sources', 'content.sources.id', '=', 'content.source_items.source_id')
        ->where('content.sources.user_id', $userId)
        ->pluck('content.source_items.record_key')
        ->all();
}

it('crosses two chunk boundaries: 7 sources at chunk size 3 project every source exactly once', function () {
    config(['partna.ingest.projection_source_chunk' => 3]);
    $userId = createTenant('chunk7-'.Str::lower(Str::random(6)))->id;
    $expectedKeys = projectableBandcampFleet(7, $userId);

    $this->artisan('ingest:project', ['--user' => $userId])->assertSuccessful();

    // Set equality, not a count: a skipped source and a duplicated one would
    // cancel out under count(), leaving the defect invisible.
    expect(projectedRecordKeys($userId))->toEqualCanonicalizing($expectedKeys);
});

it('exact multiple: 6 sources at chunk size 3 project every source exactly once (terminating-page off-by-one)', function () {
    config(['partna.ingest.projection_source_chunk' => 3]);
    $userId = createTenant('chunk6-'.Str::lower(Str::random(6)))->id;
    $expectedKeys = projectableBandcampFleet(6, $userId);

    $this->artisan('ingest:project', ['--user' => $userId])->assertSuccessful();

    expect(projectedRecordKeys($userId))->toEqualCanonicalizing($expectedKeys);
});

it('is idempotent across a chunk boundary: running twice yields identical row counts', function () {
    config(['partna.ingest.projection_source_chunk' => 3]);
    $userId = createTenant('chunkidem-'.Str::lower(Str::random(6)))->id;
    projectableBandcampFleet(7, $userId);

    $this->artisan('ingest:project', ['--user' => $userId])->assertSuccessful();
    $firstCount = count(projectedRecordKeys($userId));

    $this->artisan('ingest:project', ['--user' => $userId])->assertSuccessful();
    $secondCount = count(projectedRecordKeys($userId));

    expect($secondCount)->toBe($firstCount);
});

it('pre-fetches streams once per chunk, not once per source', function () {
    config(['partna.ingest.projection_source_chunk' => 3]);
    $userId = createTenant('chunkqc-'.Str::lower(Str::random(6)))->id;
    projectableBandcampFleet(7, $userId);

    DB::connection('pgsql')->enableQueryLog();

    $this->artisan('ingest:project', ['--user' => $userId])->assertSuccessful();

    // ceil(7/3) = 3 chunks — assert only the narrow ingest.streams query
    // subset, never a total query count, so this survives unrelated changes
    // inside ProjectionWriter. Grammar wraps schema/table separately
    // (`"ingest"."streams"`), so match both quoted parts rather than the
    // unquoted dotted form.
    $streamsQueries = array_filter(
        DB::connection('pgsql')->getQueryLog(),
        fn ($q) => str_contains($q['query'], '"ingest"') && str_contains($q['query'], '"streams"')
    );

    expect($streamsQueries)->toHaveCount(3);

    DB::connection('pgsql')->disableQueryLog();
});

// The ONGOING minter for content.item_slugs. content:backfill-item-slugs
// seeds history once; this is what keeps it populated afterwards. The lane it
// replaces had the same continuous duty (IntegrationConnectionObserver →
// EventSlugSync::syncEvents on every connect and every 6-hourly refresh), so
// without a minter here every event landed after the backfill would serve
// `slug: null` on the public pool payload forever — silently.
it('mints a public URL slug for a projected event', function () {
    [$userId, , $source, $streamId] = projectableEventbrite([
        'e/slugged' => [
            'name' => 'Laneway Session',
            'url' => 'https://www.eventbrite.com/e/laneway-session-1234567890',
            'start_date' => '2026-06-20T09:00:00+10:00',
        ],
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'events');

    $itemId = DB::table('content.items')->where('user_id', $userId)->where('kind', 'event')->value('id');

    $slug = DB::table('content.item_slugs')
        ->where('item_id', $itemId)->where('is_current', true)->value('slug');

    expect($slug)->toBe('laneway-session');
});

// Re-projection must not churn the registry — a new row per run would rotate
// the public URL and strand the last one as a 301 for no reason.
it('does not re-mint a slug when the headline is unchanged', function () {
    [$userId, , $source, $streamId] = projectableEventbrite([
        'e/stable' => [
            'name' => 'Laneway Session',
            'url' => 'https://www.eventbrite.com/e/laneway-session-1234567890',
            'start_date' => '2026-06-20T09:00:00+10:00',
        ],
    ]);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'events');
    $writer->projectStream($source, $streamId, 'events');
    $writer->projectStream($source, $streamId, 'events');

    $itemId = DB::table('content.items')->where('user_id', $userId)->where('kind', 'event')->value('id');

    expect(DB::table('content.item_slugs')->where('item_id', $itemId)->count())->toBe(1);
});

// Slugs are minted for SLUGGED_KINDS only — a release has no detail permalink,
// and minting for every kind would squat names in the (user_id, slug) unique.
it('does not mint a slug for a kind outside SLUGGED_KINDS', function () {
    [$userId, , $source, $streamId] = projectableEventbrite([
        'e/kindcheck' => [
            'name' => 'Laneway Session',
            'url' => 'https://www.eventbrite.com/e/laneway-session-1234567890',
            'start_date' => '2026-06-20T09:00:00+10:00',
        ],
    ]);
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'events');

    // Slice 4 widened the list to ['event', 'menu_item'] — exactly the pair
    // site.item_slugs covered, and the widening IS how menu slug allocation
    // re-homed off MenuItemObserver.
    expect(ContentItemSlugAllocator::SLUGGED_KINDS)->toBe(['event', 'menu_item']);
    expect(DB::table('content.item_slugs')->count())->toBe(1);
    expect(DB::table('content.items')->where('kind', 'event')->count())->toBe(1);

    // The half the test's name promises and only the const assertion used to
    // cover: an OFF-list kind lands its item and mints no slug. Asserted by
    // writing one rather than by re-reading the const, so the test would still
    // fail if refreshItemCaches() stopped consulting the list at all.
    [$offUserId] = manualSourceFor();
    app(ProjectionWriter::class)->writeManualItem($offUserId, 'manual:slug-check', [
        'kind' => 'video',
        'headline' => 'A Video With No Permalink',
    ]);

    expect(DB::table('content.items')->where('kind', 'video')->count())->toBe(1)
        ->and(DB::table('content.item_slugs')->count())->toBe(1);
});

// Slice 3b Task 5: a projection may carry a 'collections' key, and the writer
// turns it into content.collections rows plus per-source membership. These
// drive writeManualItem() rather than projectStream() on purpose —
// projectStream() re-derives every projection through a projector, which would
// make these cases depend on FreshaServiceProjector (Task 6) existing.

/** A fresh user plus its (idempotent) manual content source id. */
function manualSourceFor(): array
{
    $userId = createTenant('proj-'.Str::lower(Str::random(6)))->id;
    $sourceId = app(ProjectionWriter::class)->ensureManualSource($userId);

    return [$sourceId, $userId];
}

// projectOne() moved to tests/Helpers/ProjectionTestHelpers.php (CrossFileTestHelperGuardTest, 2026-08-26).

/** A second, distinct content.sources row (kind='connection') to prove per-source scoping. */
function otherSourceFor(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('creates a collection and links the item to it', function () {
    [$sourceId, $userId] = manualSourceFor();

    projectOne($sourceId, $userId, coord: 'fresha:x:s:1', projection: [
        'kind' => 'service', 'headline' => 'Standard Haircut',
        'collections' => [['external_ref' => '3282965', 'label' => 'Haircuts', 'kind' => 'service_category', 'position' => 0]],
    ]);

    $collection = DB::table('content.collections')
        ->where('user_id', $userId)->where('external_ref', '3282965')->first();

    expect($collection)->not->toBeNull()
        ->and($collection->label)->toBe('Haircuts')
        // Cast: SQLite hands back INTEGER 0 where Postgres hands back false —
        // a driver artefact, not a behaviour difference.
        ->and((bool) $collection->is_user_created)->toBeFalse()
        ->and(DB::table('content.collection_items')->where('collection_id', $collection->id)->count())->toBe(1);
});

it('reuses the collection on a second run and does not duplicate it', function () {
    [$sourceId, $userId] = manualSourceFor();
    $projection = [
        'kind' => 'service', 'headline' => 'Standard Haircut',
        'collections' => [['external_ref' => '3282965', 'label' => 'Haircuts', 'kind' => 'service_category', 'position' => 0]],
    ];

    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);
    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);

    expect(DB::table('content.collections')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.collection_items')->count())->toBe(1);
});

it('follows a vendor-side rename instead of minting a second collection', function () {
    [$sourceId, $userId] = manualSourceFor();
    $with = fn (string $label) => [
        'kind' => 'service', 'headline' => 'Standard Haircut',
        'collections' => [['external_ref' => '3282965', 'label' => $label, 'kind' => 'service_category', 'position' => 0]],
    ];

    projectOne($sourceId, $userId, 'fresha:x:s:1', $with('Haircuts'));
    projectOne($sourceId, $userId, 'fresha:x:s:1', $with('Haircuts & Styling'));

    $rows = DB::table('content.collections')->where('user_id', $userId)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->label)->toBe('Haircuts & Styling');
});

it('replaces memberships for its own source only', function () {
    [$sourceId, $userId] = manualSourceFor();
    $otherSourceId = otherSourceFor($userId);
    $itemId = projectOne($sourceId, $userId, 'fresha:x:s:1', [
        'kind' => 'service', 'headline' => 'Cut',
        'collections' => [['external_ref' => 'A', 'label' => 'A', 'kind' => 'service_category', 'position' => 0]],
    ]);
    $foreign = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $foreign, 'user_id' => $userId, 'parent_id' => null,
        'label' => 'Foreign', 'kind' => 'service_category', 'external_ref' => 'F',
        'position' => 0, 'is_user_created' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $foreign, 'item_id' => $itemId, 'source_id' => $otherSourceId, 'position' => 0,
    ]);

    projectOne($sourceId, $userId, 'fresha:x:s:1', [
        'kind' => 'service', 'headline' => 'Cut',
        'collections' => [['external_ref' => 'B', 'label' => 'B', 'kind' => 'service_category', 'position' => 0]],
    ]);

    $collectionB = DB::table('content.collections')
        ->where('user_id', $userId)->where('external_ref', 'B')->value('id');

    // Counts alone would pass on a writer that deleted the foreign row and
    // re-created something else — assert WHICH collection each surviving row
    // points at, and that A's membership is the one that went.
    expect(DB::table('content.collection_items')->where('source_id', $otherSourceId)->pluck('collection_id')->all())
        ->toBe([$foreign])
        ->and(DB::table('content.collection_items')->where('source_id', $sourceId)->pluck('collection_id')->all())
        ->toBe([(string) $collectionB]);
});

// Finding 1 (fix round 1): position is INSERT-ONLY. Task 9's
// ServiceCollections::reposition() does not filter is_user_created, so an owner
// can reorder a machine-derived category — and if position stayed in the
// upsert's update list the next scheduled connector run would snap that order
// back to the vendor's, silently. Same owner-intent-beats-scrape rule as
// removed_at. Note the asymmetry: label DOES follow the vendor (pinned by the
// rename case above); only position is owner-owned.
it('seeds position on insert but never overwrites an owner reorder', function () {
    [$sourceId, $userId] = manualSourceFor();
    $at = fn (int $position) => [
        'kind' => 'service', 'headline' => 'Cut',
        'collections' => [['external_ref' => 'A', 'label' => 'Haircuts', 'kind' => 'service_category', 'position' => $position]],
    ];

    // First run seeds the vendor's ordering.
    projectOne($sourceId, $userId, 'fresha:x:s:1', $at(0));
    expect(DB::table('content.collections')->where('external_ref', 'A')->value('position'))->toBe(0);

    // The owner reorders, then the vendor re-lists the category somewhere else.
    DB::table('content.collections')->where('external_ref', 'A')->update(['position' => 7]);
    projectOne($sourceId, $userId, 'fresha:x:s:1', $at(3));

    expect(DB::table('content.collections')->where('external_ref', 'A')->value('position'))->toBe(7)
        // The rename path must still work — this is not a blanket "ignore the
        // vendor", it is position specifically.
        ->and(DB::table('content.collections')->where('external_ref', 'A')->value('label'))->toBe('Haircuts')
        ->and(DB::table('content.collections')->where('user_id', $userId)->count())->toBe(1);
});

// Session 3 (2026-08-18) F30/F31: the position of an ITEM INSIDE its category
// (collection_items.position — the wire's collectionPositions, the Categories
// sheet's order). It was ProjectionWriter's per-item counter (0 for every
// single-category dish), and because a run deletes + reinserts its source's
// membership rows, an owner's in-category drag was snapped back on the next
// sync — the exact opposite of the category-position rule above.
it('seeds the in-category order from the vendor, keeps an owner reorder across runs, and appends a new dish to a curated category', function () {
    [$sourceId, $userId] = manualSourceFor();
    $dish = fn (string $name, int $itemPosition) => [
        'kind' => 'menu_item', 'headline' => $name,
        'collections' => [['external_ref' => 'menu:mains', 'label' => 'Mains', 'kind' => 'menu_category', 'position' => 0, 'item_position' => $itemPosition]],
    ];
    $a = projectOne($sourceId, $userId, 'ue:x:a', $dish('Souva', 0));
    $b = projectOne($sourceId, $userId, 'ue:x:b', $dish('Gyros', 1));
    $c = projectOne($sourceId, $userId, 'ue:x:c', $dish('Falafel', 2));
    $collectionId = DB::table('content.collections')->where('external_ref', 'menu:mains')->value('id');
    $positions = function () use ($collectionId): array {
        $out = DB::table('content.collection_items')->where('collection_id', $collectionId)->pluck('position', 'item_id')->map(fn ($p) => (int) $p)->all();
        ksort($out);

        return $out;
    };
    $expect = function (array $pairs) use ($positions): void {
        ksort($pairs);
        expect($positions())->toBe($pairs);
    };

    // Vendor order seeded 0/1/2, not 0/0/0.
    $expect([$a => 0, $b => 1, $c => 2]);

    // Owner drags Falafel to the top; a re-run of every dish keeps that.
    DB::table('content.collection_items')->where('collection_id', $collectionId)->update(['position' => DB::raw('CASE item_id WHEN \''.$c.'\' THEN 0 WHEN \''.$a.'\' THEN 1 ELSE 2 END')]);
    projectOne($sourceId, $userId, 'ue:x:a', $dish('Souva', 0));
    projectOne($sourceId, $userId, 'ue:x:b', $dish('Gyros', 1));
    projectOne($sourceId, $userId, 'ue:x:c', $dish('Falafel', 2));
    $expect([$a => 1, $b => 2, $c => 0]);

    // A NEW vendor dish lands at the end of a curated category, not at its
    // vendor index (which would interleave with the owner's arrangement).
    $d = projectOne($sourceId, $userId, 'ue:x:d', $dish('Halloumi', 1));
    expect($positions()[$d])->toBe(3);
});

it('re-seeds an uncurated all-zero category from the vendor order on the next run (the pre-fix state)', function () {
    [$sourceId, $userId] = manualSourceFor();
    $dish = fn (string $name, int $itemPosition) => [
        'kind' => 'menu_item', 'headline' => $name,
        'collections' => [['external_ref' => 'menu:sides', 'label' => 'Sides', 'kind' => 'menu_category', 'position' => 0, 'item_position' => $itemPosition]],
    ];
    $a = projectOne($sourceId, $userId, 'ue:y:a', $dish('Chips', 0));
    $b = projectOne($sourceId, $userId, 'ue:y:b', $dish('Wedges', 1));
    $collectionId = DB::table('content.collections')->where('external_ref', 'menu:sides')->value('id');
    // What every category looked like before the fix.
    DB::table('content.collection_items')->where('collection_id', $collectionId)->update(['position' => 0]);

    projectOne($sourceId, $userId, 'ue:y:b', $dish('Wedges', 1));

    $out = DB::table('content.collection_items')->where('collection_id', $collectionId)->pluck('position', 'item_id')->map(fn ($p) => (int) $p)->all();
    ksort($out);
    $want = [$a => 0, $b => 1];
    ksort($want);
    expect($out)->toBe($want);
});

it('re-seeds category positions from the vendor when every machine-derived category still sits at 0 (never arranged), and only then', function () {
    [$sourceId, $userId] = manualSourceFor();
    $svc = fn (string $name, string $ref, int $categoryPosition) => [
        'kind' => 'service', 'headline' => $name,
        'collections' => [['external_ref' => $ref, 'label' => strtoupper($ref), 'kind' => 'service_category', 'position' => $categoryPosition, 'item_position' => 0]],
    ];
    // Landed before the seeds existed: both categories at 0.
    projectOne($sourceId, $userId, 'fresha:x:s:1', $svc('Cut', 'haircut', 0));
    projectOne($sourceId, $userId, 'fresha:x:s:2', $svc('Colour', 'colour', 0));
    $pos = fn (string $ref) => (int) DB::table('content.collections')->where('user_id', $userId)->where('external_ref', $ref)->value('position');
    expect($pos('haircut'))->toBe(0)->and($pos('colour'))->toBe(0);

    // The next run carries the venue's order → taken.
    projectOne($sourceId, $userId, 'fresha:x:s:2', $svc('Colour', 'colour', 1));
    expect($pos('colour'))->toBe(1)->and($pos('haircut'))->toBe(0);

    // From here the order is owned: the vendor moving colour to 5 changes nothing.
    projectOne($sourceId, $userId, 'fresha:x:s:2', $svc('Colour', 'colour', 5));
    expect($pos('colour'))->toBe(1);
});

it('never touches removed_at on a projection run', function () {
    [$sourceId, $userId] = manualSourceFor();
    $projection = [
        'kind' => 'service', 'headline' => 'Cut',
        'collections' => [['external_ref' => 'A', 'label' => 'A', 'kind' => 'service_category', 'position' => 0]],
    ];
    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);
    DB::table('content.collections')->where('external_ref', 'A')->update(['removed_at' => now()]);

    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);

    expect(DB::table('content.collections')->where('external_ref', 'A')->value('removed_at'))->not->toBeNull();
});

it('is inert for a projection that carries no collections key', function () {
    [$sourceId, $userId] = manualSourceFor();

    projectOne($sourceId, $userId, 'fresha:x:s:1', ['kind' => 'service', 'headline' => 'Cut']);

    expect(DB::table('content.collections')->count())->toBe(0);
});

// Slice 6 §5.2: the place's aggregates have no content.items row to hang a
// facet on, so they land source-scoped on content.source_stats. The projector
// test proves the shape; this proves the WRITE — the seam that a green SQLite
// projector run says nothing about.
function projectableGoogleReviews(array $docs): array
{
    $userId = createTenant('gbstats-'.Str::lower(Str::random(6)))->id;

    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'google-business',
        'resource_id' => 'places/'.Str::random(12),
        'payload' => ['placeId' => 'places/'.Str::random(12)],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'reviews',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($docs as $key => $doc) {
        landCurrentRecord($streamId, (string) $key, $doc);
    }

    return [$userId, $connection, $source, $streamId];
}

it('lands the place aggregates on content.source_stats', function () {
    [, $connection, $source, $streamId] = projectableGoogleReviews([
        'places/p/reviews/a' => [
            'review_id' => 'places/p/reviews/a', 'rating' => 5, 'text' => 'Great.',
            'place_rating' => 4.7, 'place_rating_count' => 312,
            'place_review_summary' => 'Customers praise the friendly staff.',
        ],
        'places/p/reviews/b' => [
            'review_id' => 'places/p/reviews/b', 'rating' => 4, 'text' => 'Good.',
            'place_rating' => 4.7, 'place_rating_count' => 312,
            'place_review_summary' => 'Customers praise the friendly staff.',
        ],
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    $contentSourceId = DB::table('content.sources')->where('connection_id', $connection->id)->value('id');

    // One row per SOURCE, not per record — the aggregates are identical across
    // the run and the last one wins.
    expect(DB::table('content.source_stats')->count())->toBe(1);

    $stats = DB::table('content.source_stats')->where('source_id', $contentSourceId)->first();
    expect((float) $stats->rating_avg)->toBe(4.7)
        ->and((int) $stats->rating_count)->toBe(312)
        ->and($stats->summary_text)->toBe('Customers praise the friendly staff.');
});

// The reviewer's name must reach content.f_review and NOTHING else — slice 6
// §2.2's three-table disclosure defect, asserted at the DB grain.
it('writes the reviewer name to f_review only, never f_text or headline_cache', function () {
    [$userId, , $source, $streamId] = projectableGoogleReviews([
        'places/p/reviews/a' => [
            'review_id' => 'places/p/reviews/a', 'rating' => 5, 'text' => 'Great.',
            'author' => 'A Real Person', 'author_uri' => 'https://maps.google.com/contrib/1',
            'author_photo' => 'https://lh3.googleusercontent.com/a/abc',
        ],
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    $item = DB::table('content.items')->where('user_id', $userId)->where('kind', 'review')->first();

    expect($item->headline_cache)->toBeNull()
        ->and(DB::table('content.f_text')->where('item_id', $item->id)->count())->toBe(0)
        ->and(DB::table('content.f_review')->where('item_id', $item->id)->value('author_name'))->toBe('A Real Person')
        ->and(DB::table('content.f_review')->where('item_id', $item->id)->value('author_uri'))
        ->toBe('https://maps.google.com/contrib/1');
});

// The other half of the pair below. A run that carries SOME aggregates
// describes the place as of that run, so a key Google has stopped sending must
// be cleared — nothing else ever clears this table (the prune command touches
// f_review only, and the row goes only when content.sources cascades), so a
// withdrawn summary would otherwise be republished forever. summary_text is
// Google's prose about the business, so this is a retention question, not a
// cosmetic one.
it('clears an aggregate the later run stopped carrying', function () {
    [, $connection, $source, $streamId] = projectableGoogleReviews([
        'places/p/reviews/a' => [
            'review_id' => 'places/p/reviews/a', 'rating' => 5,
            'place_rating' => 4.7, 'place_rating_count' => 312,
            'place_review_summary' => 'Customers praise the friendly staff.',
        ],
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    $contentSourceId = DB::table('content.sources')->where('connection_id', $connection->id)->value('id');

    // Google still returns the rating, but has withdrawn the summary.
    DB::table('ingest.record_versions')->where('stream_id', $streamId)->update([
        'doc' => json_encode([
            'review_id' => 'places/p/reviews/a', 'rating' => 5,
            'place_rating' => 4.9, 'place_rating_count' => 318,
        ]),
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    $stats = DB::table('content.source_stats')->where('source_id', $contentSourceId)->first();

    expect($stats->summary_text)->toBeNull()
        ->and((float) $stats->rating_avg)->toBe(4.9)
        ->and((int) $stats->rating_count)->toBe(318);
});

// A run with no aggregates must not blank a previously-landed set.
it('leaves existing source_stats alone when a later run carries none', function () {
    [, $connection, $source, $streamId] = projectableGoogleReviews([
        'places/p/reviews/a' => [
            'review_id' => 'places/p/reviews/a', 'rating' => 5,
            'place_rating' => 4.7, 'place_rating_count' => 312,
        ],
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    $contentSourceId = DB::table('content.sources')->where('connection_id', $connection->id)->value('id');
    DB::table('ingest.record_versions')->where('stream_id', $streamId)->update([
        'doc' => json_encode(['review_id' => 'places/p/reviews/a', 'rating' => 5]),
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    expect((float) DB::table('content.source_stats')->where('source_id', $contentSourceId)->value('rating_avg'))
        ->toBe(4.7);
});

it('refreshes the merged item when only one of its coords was touched', function () {
    // Two sources carrying the SAME release url merge into one content.items
    // row via the CanonicalUrl joining key. Projecting only the SECOND stream
    // must still leave that merged item's headline_cache populated: the
    // refresh list is derived from $itemByCoord, so it names the SURVIVING
    // item rather than the touched coord's own pre-merge singleton. If the
    // narrowing had keyed off the touched coords' old item ids instead, this
    // is the test that would go red.
    $url = 'https://side.bandcamp.com/album/shared-release';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Shared Release', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Shared Release', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $items = DB::table('content.items')->where('user_id', $userId)->get(['id', 'headline_cache']);

    expect($items)->toHaveCount(1)
        ->and($items->first()->headline_cache)->toBe('Shared Release');
});
