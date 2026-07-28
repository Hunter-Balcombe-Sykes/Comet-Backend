<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\IntegrationConnection;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
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
});

/** A user + active bandcamp connection + its ingest source/stream, with $docs landed as current records. */
function projectableBandcamp(array $docs, ?string $userId = null): array
{
    $userId ??= createTenant('proj-'.Str::lower(Str::random(6)))->id;

    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => 'https://'.Str::lower(Str::random(8)).'.bandcamp.com'],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'releases',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($docs as $key => $doc) {
        landCurrentRecord($streamId, (string) $key, $doc);
    }

    return [$userId, $connection, $source, $streamId];
}

function landCurrentRecord(string $streamId, string $key, array $doc): void
{
    DB::table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
    ]);
    $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('id');
    DB::table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId,
        'last_seen_at' => now(),
    ]);
}

function bandcampDoc(string $title, string $url): array
{
    return ['title' => $title, 'url' => $url, 'artist' => 'Some Artist', 'release_date' => '2025-05-05', 'art_url' => 'https://f4.bcbits.com/img/a1_10.jpg', 'type' => 'album'];
}

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
        ->and(DB::table('content.identity_keys')->count())->toBe(2); // PlatformObject + CanonicalUrl
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

it('bumps the site build state so the document sweeper picks the change up', function () {
    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/bump' => bandcampDoc('Bump', 'https://artist.bandcamp.com/album/bump'),
    ]);
    $siteId = (string) DB::table('site.sites')->where('user_id', $userId)->value('id');
    $before = BuildState::read($siteId)['content_revision'];

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    expect(BuildState::read($siteId)['content_revision'])->toBeGreaterThan($before);
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
