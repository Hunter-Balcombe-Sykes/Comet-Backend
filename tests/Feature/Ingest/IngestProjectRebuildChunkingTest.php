<?php

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCALE-14: `ingest:project --rebuild` drops the projection-derived rows for a
// stream before re-deriving. That drop fed one unbounded id list into 18+
// whereIn DELETEs; it now chunks at partna.ingest.projection_write_chunk. Both
// halves need a guard: the drop must still reach EVERY item (a chunking bug
// that drops only the first chunk leaves orphans behind, which is the exact
// failure --rebuild exists to prevent), and it must not widen its scope to the
// user-owned rows a rebuild has to survive.
//
// Helpers are `iprc`-prefixed: unnamespaced Pest files share one global symbol
// table, so a name collision here would abort the whole suite.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

/**
 * ONE bandcamp source/stream carrying $count landed release records — i.e. one
 * content.source with $count source_items, which is the shape dropDerivedRows
 * chunks over (projectableBandcampFleet in ProjectionWriterTest is the
 * opposite shape: many sources, one item each).
 *
 * @return array{0: string, 1: array<string, mixed>, 2: string} [userId, ingest source row, streamId]
 */
function iprcSeedStream(int $count): array
{
    $userId = createTenant('iprc-'.Str::lower(Str::random(6)))->id;

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

    for ($i = 0; $i < $count; $i++) {
        // Distinct title AND url per record: identity resolution would union
        // two records sharing a canonical url into one item, collapsing the
        // id list this test needs to be $count long.
        iprcLandRecord($streamId, "album/iprc-{$i}", [
            'title' => "Rebuild Release {$i}",
            'url' => "https://iprc-{$i}.bandcamp.com/album/{$i}",
            'artist' => 'Some Artist',
            'release_date' => '2025-05-05',
            'art_url' => "https://f4.bcbits.com/img/a{$i}_10.jpg",
            'type' => 'album',
        ]);
    }

    return [$userId, $source, $streamId];
}

function iprcLandRecord(string $streamId, string $key, array $doc): void
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

/**
 * Derived rows a shape-changed projector would have orphaned, on EVERY item.
 *
 * f_action and the three unused singleton facets are the discriminating ones:
 * ProjectionWriter upserts singleton facets and never deletes them, and never
 * writes f_action at all, so only dropDerivedRows can remove these. offers and
 * item_tags are seeded too (the finding names them) but replaceCollections
 * would clear them anyway, so they prove nothing on their own.
 *
 * @param  list<string>  $itemIds
 */
function iprcSeedStaleDerived(string $contentSourceId, array $itemIds): void
{
    foreach ($itemIds as $itemId) {
        DB::table('content.f_action')->insert([
            'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $contentSourceId,
            'intent' => 'buy', 'label' => 'stale', 'url' => 'https://stale.example/'.$itemId, 'position' => 0,
        ]);
        DB::table('content.offers')->insert([
            'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $contentSourceId,
            'amount_minor' => 1500, 'currency' => 'AUD', 'qualifier' => 'exact', 'updated_at' => now(),
        ]);
        DB::table('content.item_tags')->insert([
            'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $contentSourceId,
            'tag' => 'stale-tag', 'tag_type' => 'genre',
        ]);
        DB::table('content.f_place')->insert([
            'item_id' => $itemId, 'source_id' => $contentSourceId, 'venue_name' => 'Stale Venue', 'updated_at' => now(),
        ]);
        DB::table('content.f_rated')->insert([
            'item_id' => $itemId, 'source_id' => $contentSourceId, 'rating' => 4.5, 'rating_max' => 5, 'updated_at' => now(),
        ]);
        DB::table('content.f_duration')->insert([
            'item_id' => $itemId, 'source_id' => $contentSourceId, 'seconds' => 321, 'updated_at' => now(),
        ]);
    }
}

/** @return list<string> distinct item ids projected for this content source. */
function iprcItemIds(string $contentSourceId): array
{
    return DB::table('content.source_items')
        ->where('source_id', $contentSourceId)
        ->whereNotNull('item_id')
        ->pluck('item_id')
        ->unique()
        ->values()
        ->all();
}

it('drops every chunk of derived rows under --rebuild, not just the first chunk', function () {
    config(['partna.ingest.projection_write_chunk' => 3]);
    [$userId, $source] = iprcSeedStream(7);

    // Plain run first, so the stale rows below hang off real items.
    $this->artisan('ingest:project', ['--source' => $source['id']])->assertSuccessful();

    $contentSourceId = (string) DB::table('content.sources')->where('connection_id', $source['connection_id'])->value('id');
    $itemIds = iprcItemIds($contentSourceId);

    // 7 at chunk 3 is >2x the chunk size: a drop that only ever ran its first
    // chunk would leave 4 items' rows behind, which a 2x fixture would not
    // distinguish from an off-by-one.
    expect($itemIds)->toHaveCount(7);

    iprcSeedStaleDerived($contentSourceId, $itemIds);

    // User state a rebuild must survive. Projection writes anchors of its own,
    // so assert on this specific hand-placed coord rather than a count.
    DB::table('content.item_anchors')->insert([
        'coord' => 'iprc:manual-anchor', 'user_id' => $userId, 'item_id' => $itemIds[6],
        'bound_at' => now(),
    ]);
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemIds[6], 'facet' => 'f_text',
        'column_name' => 'headline', 'value' => 'Hand-edited', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('ingest:project', ['--rebuild' => true, '--source' => $source['id']])->assertSuccessful();

    foreach (['f_action', 'offers', 'item_tags', 'f_place', 'f_rated', 'f_duration'] as $table) {
        expect(DB::table("content.{$table}")->where('source_id', $contentSourceId)->count())
            ->toBe(0, "stale content.{$table} rows survived --rebuild");
    }

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(7)
        ->and(DB::table('content.source_items')->where('source_id', $contentSourceId)->count())->toBe(7)
        ->and(DB::table('content.item_anchors')->where('coord', 'iprc:manual-anchor')->exists())->toBeTrue()
        ->and(DB::table('content.manual_overrides')->where('value', 'Hand-edited')->exists())->toBeTrue();

    // The drop is only half of --rebuild: what the projector still emits must
    // come back for every item, not just the chunks the drop reached.
    expect(DB::table('content.f_link')->where('source_id', $contentSourceId)->count())->toBe(7)
        ->and(DB::table('content.item_media')->where('source_id', $contentSourceId)->count())->toBe(7)
        ->and(DB::table('content.identity_keys')->count())->toBe(14); // PlatformObject + CanonicalUrl per source item
});

it('issues one DELETE per derived table per chunk, so statement count tracks ceil(n/chunk) not n', function () {
    config(['partna.ingest.projection_write_chunk' => 3]);
    [, $source] = iprcSeedStream(7);

    $this->artisan('ingest:project', ['--source' => $source['id']])->assertSuccessful();

    // content.f_place is deleted ONLY by dropDerivedRows — projection upserts
    // singleton facets and never deletes them — so counting DELETEs against it
    // isolates the drop from everything projectStream does afterwards.
    $deletes = 0;
    DB::listen(function ($query) use (&$deletes) {
        if (str_starts_with($query->sql, 'delete') && str_contains($query->sql, '"f_place"')) {
            $deletes++;
        }
    });

    $this->artisan('ingest:project', ['--rebuild' => true, '--source' => $source['id']])->assertSuccessful();

    // ceil(7/3) = 3. One unbounded DELETE reads as 1 here, and an
    // accidentally per-item loop would read as 7.
    expect($deletes)->toBe(3);
});
