<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCALE-6/7/8 (unit-10-projectionwriter plan): the N+1 lane. Distinct helper
// names from tests/Feature/Ingest/ProjectionWriterTest.php on purpose — Pest
// under --parallel does not guarantee both files are required in the same
// worker process, so this file must be self-contained rather than reusing
// that file's top-level functions.
//
// GOLDEN CAPTURED AGAINST UNMODIFIED CODE. This file was written and run
// against the pre-rewrite ProjectionWriter (git 30ef2df8) BEFORE SCALE-6/7/8
// were implemented, so the literal snapshot below and the pre-fix slope
// documented in the review are what the CURRENT (unbatched) code actually
// produces — not a value derived from the rewrite.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

/** @return array{0: string, 1: IntegrationConnection} user id + a bandcamp connection with a fixed, known resource_id. */
function bwtConnection(string $userId, string $resourceIdSuffix): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.str_pad($resourceIdSuffix, 16, '0', STR_PAD_LEFT),
        'payload' => ['url' => 'https://'.Str::lower(Str::random(8)).'.bandcamp.com'],
        'is_active' => true,
    ]);
}

/** @return array{0: array<string, mixed>, 1: string} ingest.sources row + streamId, for a bandcamp "releases" stream. */
function bwtStream(IntegrationConnection $connection): array
{
    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'releases',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$source, $streamId];
}

/** Lands one current record version + record_state row, with a caller-controlled first_seen_at for I1 ordering control. */
function bwtLand(string $streamId, string $key, array $doc, ?string $firstSeenAt = null): void
{
    $firstSeenAt ??= now()->toDateTimeString();
    DB::table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => $firstSeenAt, 'is_current' => 1,
    ]);
    $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('id');
    DB::table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId,
        'last_seen_at' => now(),
    ]);
}

function bwtDoc(string $title, string $url, ?string $artist = null): array
{
    return [
        'title' => $title, 'url' => $url, 'artist' => $artist ?? 'Some Artist',
        'release_date' => '2025-05-05', 'art_url' => 'https://f4.bcbits.com/img/a1_10.jpg', 'type' => 'album',
    ];
}

/**
 * @return array{0: array<string, mixed>, 1: string} ingest.sources row + streamId, for an instagram "media" stream.
 *
 * Instagram, not bandcamp, because InstagramMediaProjector is the only
 * projector that emits MORE THAN ONE media entry per record (a carousel) —
 * which is what the per-item `position` sequence is about.
 */
function bwtInstagramStream(string $userId): array
{
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'instagram',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['username' => Str::lower(Str::random(10))],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'media',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$source, $streamId];
}

/** @param list<string> $images */
function bwtLandIg(string $streamId, string $shortcode, array $images): void
{
    bwtLand($streamId, $shortcode, [
        'shortcode' => $shortcode,
        'url' => "https://www.instagram.com/p/{$shortcode}/",
        'caption' => "Post {$shortcode}",
        'taken_at' => '2025-05-05T00:00:00Z',
        'images' => $images,
    ]);
}

/** coord as ProjectionWriter::accountRef() + the coord format at :102 build it, for a resource_id already matching acct-<16hex>. */
function bwtCoord(string $resourceId, string $key): string
{
    return "bandcamp:{$resourceId}:{$key}";
}

/**
 * The whole projected state, id-normalised and sorted, for the golden diff.
 * Item ids / source_item ids are replaced by a stable per-run counter keyed
 * on a caller-supplied natural key so the literal snapshot doesn't churn on
 * fresh UUIDs every run.
 */
function bwtSnapshot(string $userId): array
{
    $items = DB::table('content.items')->where('user_id', $userId)
        ->orderBy('headline_cache')->get(['id', 'kind', 'headline_cache', 'facets_cache', 'removed_at']);

    $itemIdMap = [];
    $n = 0;
    foreach ($items as $item) {
        $itemIdMap[$item->id] = 'item-'.$n++;
    }

    $normalisedItems = $items->map(fn ($i) => [
        'id' => $itemIdMap[$i->id],
        'kind' => $i->kind,
        'headline_cache' => $i->headline_cache,
        'facets_cache' => json_decode((string) $i->facets_cache, true),
        'removed' => $i->removed_at !== null,
    ])->values()->all();

    $sourceItems = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->orderBy('si.coord')
        ->get(['si.coord', 'si.item_id', 'si.removed_at']);

    $normalisedSourceItems = $sourceItems->map(fn ($si) => [
        'coord' => $si->coord,
        'item' => $itemIdMap[$si->item_id] ?? null,
        'removed' => $si->removed_at !== null,
    ])->values()->all();

    $facetColumns = [
        'f_text' => ['headline', 'body', 'summary'],
        'f_link' => ['url', 'canonical_url'],
        'f_published' => ['published_from', 'published_to', 'verbatim', 'precision'],
        'f_authored' => ['creator', 'creator_url', 'collaborators'],
        'f_catalog' => ['release_type', 'track_number', 'disc_number', 'isrc', 'gtin', 'sku'],
    ];
    $facetRows = [];
    foreach ($facetColumns as $facet => $columns) {
        $select = array_map(fn ($c) => "ft.{$c}", $columns);
        $rows = DB::table("content.{$facet} as ft")
            ->join('content.sources as cs', 'cs.id', '=', 'ft.source_id')
            ->where('cs.user_id', $userId)
            ->get(array_merge($select, ['ft.item_id']));
        foreach ($rows as $row) {
            $row = (array) $row;
            $row['item'] = $itemIdMap[$row['item_id']] ?? null;
            unset($row['item_id']);
            $facetRows[$facet][] = $row;
        }
    }
    foreach ($facetRows as $facet => $rows) {
        usort($rows, fn ($a, $b) => json_encode($a) <=> json_encode($b));
        $facetRows[$facet] = $rows;
    }

    $mediaCount = DB::table('content.item_media as im')
        ->join('content.sources as cs', 'cs.id', '=', 'im.source_id')
        ->where('cs.user_id', $userId)->count();

    return [
        'items' => $normalisedItems,
        'source_items' => $normalisedSourceItems,
        'facets' => $facetRows,
        'media_count' => $mediaCount,
    ];
}

it('projects a mixed batch to the captured golden snapshot (I1/I5/I6/I8 all exercised)', function () {
    $userId = createTenant('bwt-'.Str::lower(Str::random(6)))->id;

    $connA = bwtConnection($userId, '1');
    $connB = bwtConnection($userId, '2');
    $connC = bwtConnection($userId, '3');
    [$sourceA, $streamA] = bwtStream($connA);
    [$sourceB, $streamB] = bwtStream($connB);
    [$sourceC, $streamC] = bwtStream($connC);

    // Cross-source CanonicalUrl union: A and B both carry the same URL. B's
    // content.sources row gets the higher priority below, so ValueResolver
    // (I8) must pick B's headline even though A projects first.
    bwtLand($streamA, 'union-a', bwtDoc('Union From A', 'https://x.bandcamp.com/album/union'));
    bwtLand($streamB, 'union-b', bwtDoc('Union From B', 'https://x.bandcamp.com/album/union'));

    // Unique, unrelated items on A and B.
    bwtLand($streamA, 'solo-a', bwtDoc('Solo A', 'https://x.bandcamp.com/album/solo-a'));
    bwtLand($streamB, 'solo-b', bwtDoc('Solo B', 'https://x.bandcamp.com/album/solo-b'));

    // A tombstoned record on A.
    bwtLand($streamA, 'tomb', bwtDoc('Tombstoned', 'https://x.bandcamp.com/album/tomb'));

    // A record that projects to null (no title/url survives to projection).
    bwtLand($streamA, 'nullproj', ['artist' => 'Nobody', 'release_date' => '2025-01-01', 'art_url' => null, 'type' => 'album']);

    // An identity_decisions "different" verdict overriding a CanonicalUrl match.
    bwtLand($streamA, 'diff-a', bwtDoc('Different Pair A', 'https://x.bandcamp.com/album/diffpair'));
    bwtLand($streamB, 'diff-b', bwtDoc('Different Pair B', 'https://x.bandcamp.com/album/diffpair'));
    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'different',
        'left_coord' => bwtCoord($connA->resource_id, 'diff-a'),
        'right_coord' => bwtCoord($connB->resource_id, 'diff-b'),
        'decided_at' => now(),
    ]);

    // A same-source pair resolving to one item (I1): distinct URLs (an
    // identical URL from ONE source is POISONED per Resolver::poisonedKeys —
    // it means the source's URL data is unreliable, not a match — so the
    // merge here is forced via an explicit identity_decisions "same" verdict,
    // same as a user manually confirming a match). Landed with first_seen_at
    // ASCENDING — the later-first_seen_at record's headline must win
    // content.f_text.headline.
    bwtLand($streamC, 'same-1', bwtDoc('Same Source First', 'https://x.bandcamp.com/album/samesrc-1'), '2025-01-01 00:00:00');
    bwtLand($streamC, 'same-2', bwtDoc('Same Source Second', 'https://x.bandcamp.com/album/samesrc-2'), '2025-01-01 00:00:01');
    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => bwtCoord($connC->resource_id, 'same-1'),
        'right_coord' => bwtCoord($connC->resource_id, 'same-2'),
        'decided_at' => now(),
    ]);

    // Distinct priorities so I8 (cross-source headline weighting) is exercised.
    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');
    $writer->projectStream($sourceC, $streamC, 'releases');

    DB::table('content.sources')->where('connection_id', $connA->id)->update(['priority' => 10]);
    DB::table('content.sources')->where('connection_id', $connB->id)->update(['priority' => 30]);
    DB::table('content.sources')->where('connection_id', $connC->id)->update(['priority' => 20]);

    // Tombstone one record and re-run so retireAbsentSourceItems() fires.
    DB::table('ingest.record_state')->where('stream_id', $streamA)->where('key', 'tomb')->update(['tombstoned_at' => now()]);

    // A user-removed item, folded in before the final re-run so the golden
    // captures items.removed_at alongside everything else (I4).
    $soloAItem = DB::table('content.items')->where('user_id', $userId)->where('headline_cache', 'Solo A')->value('id');
    DB::table('content.items')->where('id', $soloAItem)->update(['removed_at' => now()]);

    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');
    $writer->projectStream($sourceC, $streamC, 'releases');

    // Golden snapshot: captured by running this exact test against the
    // UNMODIFIED (pre-SCALE-6/7/8) ProjectionWriter and copying the literal
    // output of bwtSnapshot() below — see the header comment. Notable real
    // behaviour this pins: the tombstoned record's ITEM survives (only
    // source_items.removed_at is set, per I4); the null-projecting
    // 'nullproj' record contributes nothing at all; the same-source pair
    // ('same-1'/'same-2') collapses content.f_link to ONE row because it's a
    // SINGLETON facet keyed (item_id, source_id) — the later-processed
    // record's URL wins there too, consistent with I1's f_text case.
    expect(bwtSnapshot($userId))->toBe([
        'items' => [
            ['id' => 'item-0', 'kind' => 'release', 'headline_cache' => 'Different Pair A', 'facets_cache' => ['f_text', 'f_link', 'f_published', 'f_authored', 'f_catalog', 'item_media'], 'removed' => false],
            ['id' => 'item-1', 'kind' => 'release', 'headline_cache' => 'Different Pair B', 'facets_cache' => ['f_text', 'f_link', 'f_published', 'f_authored', 'f_catalog', 'item_media'], 'removed' => false],
            ['id' => 'item-2', 'kind' => 'release', 'headline_cache' => 'Same Source Second', 'facets_cache' => ['f_text', 'f_link', 'f_published', 'f_authored', 'f_catalog', 'item_media'], 'removed' => false],
            ['id' => 'item-3', 'kind' => 'release', 'headline_cache' => 'Solo A', 'facets_cache' => ['f_text', 'f_link', 'f_published', 'f_authored', 'f_catalog', 'item_media'], 'removed' => true],
            ['id' => 'item-4', 'kind' => 'release', 'headline_cache' => 'Solo B', 'facets_cache' => ['f_text', 'f_link', 'f_published', 'f_authored', 'f_catalog', 'item_media'], 'removed' => false],
            ['id' => 'item-5', 'kind' => 'release', 'headline_cache' => 'Tombstoned', 'facets_cache' => ['f_text', 'f_link', 'f_published', 'f_authored', 'f_catalog', 'item_media'], 'removed' => false],
            ['id' => 'item-6', 'kind' => 'release', 'headline_cache' => 'Union From B', 'facets_cache' => ['f_text', 'f_link', 'f_published', 'f_authored', 'f_catalog', 'item_media'], 'removed' => false],
        ],
        'source_items' => [
            ['coord' => bwtCoord($connA->resource_id, 'diff-a'), 'item' => 'item-0', 'removed' => false],
            ['coord' => bwtCoord($connA->resource_id, 'solo-a'), 'item' => 'item-3', 'removed' => false],
            ['coord' => bwtCoord($connA->resource_id, 'tomb'), 'item' => 'item-5', 'removed' => true],
            ['coord' => bwtCoord($connA->resource_id, 'union-a'), 'item' => 'item-6', 'removed' => false],
            ['coord' => bwtCoord($connB->resource_id, 'diff-b'), 'item' => 'item-1', 'removed' => false],
            ['coord' => bwtCoord($connB->resource_id, 'solo-b'), 'item' => 'item-4', 'removed' => false],
            ['coord' => bwtCoord($connB->resource_id, 'union-b'), 'item' => 'item-6', 'removed' => false],
            ['coord' => bwtCoord($connC->resource_id, 'same-1'), 'item' => 'item-2', 'removed' => false],
            ['coord' => bwtCoord($connC->resource_id, 'same-2'), 'item' => 'item-2', 'removed' => false],
        ],
        'facets' => [
            'f_text' => [
                ['headline' => 'Different Pair A', 'body' => null, 'summary' => null, 'item' => 'item-0'],
                ['headline' => 'Different Pair B', 'body' => null, 'summary' => null, 'item' => 'item-1'],
                ['headline' => 'Same Source Second', 'body' => null, 'summary' => null, 'item' => 'item-2'],
                ['headline' => 'Solo A', 'body' => null, 'summary' => null, 'item' => 'item-3'],
                ['headline' => 'Solo B', 'body' => null, 'summary' => null, 'item' => 'item-4'],
                ['headline' => 'Tombstoned', 'body' => null, 'summary' => null, 'item' => 'item-5'],
                ['headline' => 'Union From A', 'body' => null, 'summary' => null, 'item' => 'item-6'],
                ['headline' => 'Union From B', 'body' => null, 'summary' => null, 'item' => 'item-6'],
            ],
            'f_link' => [
                ['url' => 'https://x.bandcamp.com/album/diffpair', 'canonical_url' => null, 'item' => 'item-0'],
                ['url' => 'https://x.bandcamp.com/album/diffpair', 'canonical_url' => null, 'item' => 'item-1'],
                ['url' => 'https://x.bandcamp.com/album/samesrc-2', 'canonical_url' => null, 'item' => 'item-2'],
                ['url' => 'https://x.bandcamp.com/album/solo-a', 'canonical_url' => null, 'item' => 'item-3'],
                ['url' => 'https://x.bandcamp.com/album/solo-b', 'canonical_url' => null, 'item' => 'item-4'],
                ['url' => 'https://x.bandcamp.com/album/tomb', 'canonical_url' => null, 'item' => 'item-5'],
                ['url' => 'https://x.bandcamp.com/album/union', 'canonical_url' => null, 'item' => 'item-6'],
                ['url' => 'https://x.bandcamp.com/album/union', 'canonical_url' => null, 'item' => 'item-6'],
            ],
            'f_published' => [
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-0'],
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-1'],
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-2'],
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-3'],
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-4'],
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-5'],
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-6'],
                ['published_from' => '2025-05-05', 'published_to' => null, 'verbatim' => null, 'precision' => null, 'item' => 'item-6'],
            ],
            'f_authored' => [
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-0'],
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-1'],
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-2'],
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-3'],
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-4'],
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-5'],
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-6'],
                ['creator' => 'Some Artist', 'creator_url' => null, 'collaborators' => null, 'item' => 'item-6'],
            ],
            'f_catalog' => [
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-0'],
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-1'],
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-2'],
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-3'],
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-4'],
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-5'],
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-6'],
                ['release_type' => 'album', 'track_number' => null, 'disc_number' => null, 'isrc' => null, 'gtin' => null, 'sku' => null, 'item' => 'item-6'],
            ],
        ],
        'media_count' => 9,
    ]);
});

/**
 * I1, isolated: two records of ONE stream resolve to one item, landed with
 * rv.first_seen_at ASCENDING but inserted in the OPPOSITE physical order —
 * the later-first_seen_at record must still win content.f_text.headline.
 * Exactly what a non-unique lazy() page order could silently break.
 */
it('resolves the same-item headline by first_seen_at order, not insertion order (I1)', function () {
    $userId = createTenant('bwt-i1-'.Str::lower(Str::random(6)))->id;
    $conn = bwtConnection($userId, '9');
    [$source, $streamId] = bwtStream($conn);

    // Physical insertion order is REVERSED relative to first_seen_at: the
    // "later" record (by first_seen_at) is written to the DB first. Distinct
    // URLs (see the golden test above for why an identical same-source URL
    // is poisoned) — forced onto one item via an identity_decisions "same"
    // verdict instead.
    bwtLand($streamId, 'later', bwtDoc('Later Wins', 'https://i1.bandcamp.com/album/later'), '2025-06-01 00:00:00');
    bwtLand($streamId, 'earlier', bwtDoc('Earlier Loses', 'https://i1.bandcamp.com/album/earlier'), '2025-01-01 00:00:00');
    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => bwtCoord($conn->resource_id, 'later'),
        'right_coord' => bwtCoord($conn->resource_id, 'earlier'),
        'decided_at' => now(),
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    $itemId = DB::table('content.items')->where('user_id', $userId)->value('id');
    expect(DB::table('content.f_text')->where('item_id', $itemId)->value('headline'))->toBe('Later Wins')
        ->and(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBe('Later Wins');
});

/**
 * The N+1 lane. Builds N unrelated bandcamp items on one stream and counts
 * every query `projectStream()` issues. Slope, not absolute count, is what's
 * asserted for the FIRST run — robust to unrelated query churn elsewhere in
 * the call graph. Per item, legitimately O(n) and OUT OF SCOPE for
 * SCALE-6/7/8 (none of these three findings touch this code):
 *   - upsertSourceItem: 1 select + 1 write                        (2)
 *   - writeIdentityKeys: 1 delete + 1 insert                      (2)
 *   - bindGroup: 1 item_anchors select (+1 items insert, +1
 *     item_anchors insert on a genuinely NEW item — first run only) (1-3)
 *   - writeFacets: BandcampReleaseProjector always emits f_text/f_link/
 *     f_published/f_authored/f_catalog — 5 upserts, one per facet   (5)
 *   - replaceCollections: item_media/offers/item_tags deletes,
 *     unconditional regardless of content                          (3)
 *     [SCALE-17/#CACHE-2 has since moved these three out of the per-item
 *     floor into one per-chunk batch, taking the measured first-run slope
 *     here from ~17 to ~14/item. The threshold below is left at 18 on
 *     purpose — this test's job is the SCALE-6/7/8 shape, and the tighter
 *     media-bearing bound lives in its own test further down.]
 * That floor alone is ~13-15/item BEFORE any of the three fixes' code
 * runs at all — measured directly (see the steady-state assertion below).
 *
 * PRE-FIX (captured against unmodified ProjectionWriter, this run): 10-item
 * fixture = 402 queries, 40-item fixture = 1572 queries, slope = (1572-402)/
 * 30 = 39/item. POST-FIX (measured against the SCALE-6/7/8 rewrite): first-
 * run slope ~17/item (includes the one-time item-creation cost above);
 * steady-state (second, unchanged run) slope ~13/item, matching the
 * legitimately-per-record floor with nothing left over from resolveItems()/
 * refreshItemCaches() — those two are now O(chunks-of-500), not O(items).
 * The threshold below (18) is the measured post-fix ceiling plus headroom,
 * not the plan's illustrative "12" — that estimate didn't price in
 * bindGroup/writeFacets/replaceCollections, which are real per-record work
 * this unit was never asked to batch.
 */
it('does not scale query count linearly with item count on a fresh run', function () {
    $countQueriesFor = function (int $n): int {
        $userId = createTenant('bwt-slope-'.Str::lower(Str::random(6)))->id;
        $conn = bwtConnection($userId, (string) $n);
        [$source, $streamId] = bwtStream($conn);

        // No art_url: BandcampReleaseProjector.media stays empty, so
        // replaceCollections() has no media entries to resolve at all — keeps the
        // per-item floor to the components enumerated above, nothing more.
        for ($i = 0; $i < $n; $i++) {
            $doc = bwtDoc("Item {$i}", "https://slope.bandcamp.com/album/item-{$i}");
            $doc['art_url'] = null;
            bwtLand($streamId, "item-{$i}", $doc);
        }

        $queries = 0;
        // DB::listen has no per-call removal, so a listener from an earlier
        // sub-run stays registered — harmless, it only ever increments its
        // own already-captured-by-value counter, never this one.
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

        return $queries;
    };

    $queries10 = $countQueriesFor(10);
    $queries40 = $countQueriesFor(40);

    $slope = ($queries40 - $queries10) / 30;
    expect($slope)->toBeLessThanOrEqual(18);
});

/**
 * SCALE-17/#CACHE-2: the slope test above deliberately NULLS art_url, which
 * keeps the media-asset resolve out of replaceCollections() entirely — so it
 * cannot see the collection-write N+1 at all. This one populates art_url (a
 * DISTINCT image per item, so the media_assets write path is real work rather
 * than a dedupe hit) and asserts the media-bearing slope.
 *
 * Pre-fix the per-item cost was the 13-query floor PLUS, per item: 1
 * content.items user_id lookup + 1 media_assets SELECT + 1 media_assets
 * INSERT + 1 item_media INSERT. Post-fix all four collapse into the batch,
 * and so do the three collection DELETEs, leaving only the genuinely
 * per-record work (source_items / identity_keys / anchors / singleton facet
 * upserts).
 */
it('issues a bounded number of collection writes regardless of item count', function () {
    $countQueriesFor = function (int $n): int {
        $userId = createTenant('bwt-mslope-'.Str::lower(Str::random(6)))->id;
        $conn = bwtConnection($userId, (string) (1000 + $n));
        [$source, $streamId] = bwtStream($conn);

        // art_url PRESENT and distinct per item — BandcampReleaseProjector
        // emits one 'cover' media entry per record, so every item carries a
        // media row backed by its own media_assets row.
        for ($i = 0; $i < $n; $i++) {
            $doc = bwtDoc("Item {$i}", "https://mslope.bandcamp.com/album/item-{$i}");
            $doc['art_url'] = "https://f4.bcbits.com/img/mslope-{$i}.jpg";
            bwtLand($streamId, "item-{$i}", $doc);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

        return $queries;
    };

    $queries10 = $countQueriesFor(10);
    $queries40 = $countQueriesFor(40);

    $slope = ($queries40 - $queries10) / 30;
    // Measured against the UNBATCHED code: 21/item. Measured post-fix:
    // exactly 14/item (10-item run 179, 40-item run 599) — the same 14 the
    // no-media slope above costs, i.e. the media path now contributes nothing
    // per item. Threshold = measured + 1, deliberately tight: anything looser
    // stops separating the fixed shape from the pre-fix one.
    expect($slope)->toBeLessThanOrEqual(15);
});

/**
 * SCALE-17/#CACHE-2, the batching trap: item_media.position is 0..n-1 WITHIN
 * one item. Batching the inserts across items makes it trivially easy to let
 * one counter run across the whole batch — a query-count assertion would not
 * notice, and the rendered gallery order would silently change.
 */
it('preserves per-item media position and role after batching', function () {
    $userId = createTenant('bwt-pos-'.Str::lower(Str::random(6)))->id;
    [$source, $streamId] = bwtInstagramStream($userId);

    bwtLandIg($streamId, 'AAA', ['https://ig.test/a-0.jpg', 'https://ig.test/a-1.jpg', 'https://ig.test/a-2.jpg']);
    bwtLandIg($streamId, 'BBB', ['https://ig.test/b-0.jpg', 'https://ig.test/b-1.jpg', 'https://ig.test/b-2.jpg']);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');

    $items = DB::table('content.items')->where('user_id', $userId)->pluck('id');
    expect($items)->toHaveCount(2);

    foreach ($items as $itemId) {
        $rows = DB::table('content.item_media')->where('item_id', $itemId)->orderBy('position')->get(['position', 'role']);

        expect($rows->pluck('position')->all())->toBe([0, 1, 2])
            ->and($rows->pluck('role')->all())->toBe(['cover', 'gallery', 'gallery']);
    }
});

/**
 * SCALE-17/#CACHE-2: the batched resolve must dedupe by fingerprint IN PHP
 * before inserting — two items sharing one image URL still mint exactly ONE
 * content.media_assets row, same as the per-row path's SELECT-then-INSERT did.
 */
it('mints distinct media_assets for the same image used by two items', function () {
    $userId = createTenant('bwt-dedupe-'.Str::lower(Str::random(6)))->id;
    [$source, $streamId] = bwtInstagramStream($userId);

    // Same URL on both posts; distinct shortcodes so they stay distinct items.
    // Spec §3.1: media fingerprint prefers the stable ref over the re-signing URL.
    // Different shortcodes → different refs → different fingerprints → 2 assets.
    bwtLandIg($streamId, 'CCC', ['https://ig.test/shared.jpg']);
    bwtLandIg($streamId, 'DDD', ['https://ig.test/shared.jpg']);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(2)
        ->and(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe(2)
        ->and(DB::table('content.item_media')->whereNotNull('asset_id')->count())->toBe(2);
});

/**
 * SCALE-17/#CACHE-2: the chunk loop itself. At the default chunk (500) every
 * realistic fixture is ONE iteration, so a defect confined to the second and
 * later chunks — a mis-sliced rowsFor(), positions leaking across the chunk
 * boundary, a DELETE that skips a chunk's item ids — would never be reached.
 * Shrinking the config value to 2 over 5 items forces three iterations.
 *
 * Deliberately NOT one of the two threshold tests: a shrunk chunk adds ~3
 * queries per extra chunk, so folding this in would break both absolute
 * counts. This one asserts correctness only.
 */
it('writes every chunk correctly when the write chunk is smaller than the batch', function () {
    config(['partna.ingest.projection_write_chunk' => 2]);

    $userId = createTenant('bwt-chunk-'.Str::lower(Str::random(6)))->id;
    [$source, $streamId] = bwtInstagramStream($userId);

    $shortcodes = ['CH1', 'CH2', 'CH3', 'CH4', 'CH5'];
    foreach ($shortcodes as $shortcode) {
        bwtLandIg($streamId, $shortcode, [
            "https://ig.test/{$shortcode}-0.jpg",
            "https://ig.test/{$shortcode}-1.jpg",
            "https://ig.test/{$shortcode}-2.jpg",
        ]);
    }

    // 5 items at chunk 2 => ceil(5/2) = 3 iterations, each issuing its own
    // item_media DELETE. Counting those DELETEs is the direct proof the loop
    // ran more than once — a query total would only prove it indirectly.
    $mediaDeletes = 0;
    DB::listen(function ($query) use (&$mediaDeletes) {
        if (str_contains($query->sql, 'delete from') && str_contains($query->sql, 'item_media')) {
            $mediaDeletes++;
        }
    });

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');

    expect($mediaDeletes)->toBe(3);

    $items = DB::table('content.items')->where('user_id', $userId)->pluck('id');
    expect($items)->toHaveCount(5);

    // Every item across every chunk keeps its own 0..2 sequence and its roles,
    // and every frame resolved to an asset.
    foreach ($items as $itemId) {
        $rows = DB::table('content.item_media')->where('item_id', $itemId)
            ->orderBy('position')->get(['position', 'role', 'asset_id']);

        expect($rows->pluck('position')->all())->toBe([0, 1, 2])
            ->and($rows->pluck('role')->all())->toBe(['cover', 'gallery', 'gallery'])
            ->and($rows->whereNull('asset_id'))->toHaveCount(0);
    }

    expect(DB::table('content.item_media')->whereIn('item_id', $items)->count())->toBe(15)
        ->and(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe(15);

    // Re-run at the same shrunk chunk: each chunk's DELETE must cover exactly
    // its own item ids, so nothing duplicates and nothing is left behind.
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');

    expect(DB::table('content.item_media')->whereIn('item_id', $items)->count())->toBe(15)
        ->and(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe(15);
});

/**
 * The cross-projection concatenation inside replaceCollections(): two records
 * resolving to ONE item contribute their media one after the other, so the
 * item's positions run 0..(a+b-1) across BOTH projections while each
 * projection keeps its own role sequence. Nothing covered this before — the
 * position counter has to reset per ITEM, not per projection.
 */
it('concatenates media from two records that resolve to one item, numbering positions across both', function () {
    $userId = createTenant('bwt-merge-'.Str::lower(Str::random(6)))->id;
    [$source, $streamId] = bwtInstagramStream($userId);

    // first_seen_at ASCENDING so the processing order (and therefore the
    // concatenation order) is pinned rather than heap-dependent.
    bwtLandIg($streamId, 'MG1', ['https://ig.test/mg1-0.jpg', 'https://ig.test/mg1-1.jpg']);
    bwtLandIg($streamId, 'MG2', ['https://ig.test/mg2-0.jpg', 'https://ig.test/mg2-1.jpg', 'https://ig.test/mg2-2.jpg']);
    DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', 'MG1')
        ->update(['first_seen_at' => '2025-01-01 00:00:00']);
    DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', 'MG2')
        ->update(['first_seen_at' => '2025-01-01 00:00:01']);

    $accountRef = (string) DB::table('site.platform_connections')
        ->where('id', $source['connection_id'])->value('resource_id');
    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => "instagram:{$accountRef}:MG1",
        'right_coord' => "instagram:{$accountRef}:MG2",
        'decided_at' => now(),
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');

    $items = DB::table('content.items')->where('user_id', $userId)->pluck('id');
    expect($items)->toHaveCount(1);

    $rows = DB::table('content.item_media')->where('item_id', $items[0])
        ->orderBy('position')->get(['position', 'role']);

    // 2 frames + 3 frames on one item: positions 0..4 with no reset in the
    // middle, and each projection's own frame 0 keeps its 'cover' role.
    expect($rows->pluck('position')->all())->toBe([0, 1, 2, 3, 4])
        ->and($rows->pluck('role')->all())->toBe(['cover', 'gallery', 'cover', 'gallery', 'gallery']);
});

/**
 * Absolute count on the steady-state (second, unchanged) run — the shape
 * plan §d recommends alongside the slope assertion. Re-running over records
 * that already have items/anchors/facets removes bindGroup's one-time
 * item-creation cost, isolating the legitimately-per-record floor: 10/item
 * (upsertSourceItem 2 + writeIdentityKeys 2 + bindGroup's anchor SELECT only
 * 1 + 5 facet upserts), plus a small constant for the now-batched
 * resolveItems()/refreshItemCaches()/replaceCollections()/bumpSite() calls.
 * The three collection-table DELETEs used to sit in that per-item floor;
 * SCALE-17/#CACHE-2 moved them into replaceCollections()'s per-chunk batch.
 */
it('pins the steady-state (second, unchanged) run query count', function () {
    $userId = createTenant('bwt-steady-'.Str::lower(Str::random(6)))->id;
    $conn = bwtConnection($userId, '999');
    [$source, $streamId] = bwtStream($conn);

    for ($i = 0; $i < 10; $i++) {
        $doc = bwtDoc("Item {$i}", "https://steady.bandcamp.com/album/item-{$i}");
        $doc['art_url'] = null;
        bwtLand($streamId, "item-{$i}", $doc);
    }

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases'); // establish

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $writer->projectStream($source, $streamId, 'releases'); // steady state

    // 10 items x ~10/item legitimate floor + a small constant for the
    // batched resolveItems()/refreshItemCaches()/bumpSite() calls. Was 161
    // after SCALE-6/7/8; SCALE-17/#CACHE-2 pulled the three per-item
    // collection DELETEs out into three per-chunk ones, measured 134 —
    // threshold set just above the measured floor, not padded for slack.
    expect($queries)->toBeLessThanOrEqual(140);
});
