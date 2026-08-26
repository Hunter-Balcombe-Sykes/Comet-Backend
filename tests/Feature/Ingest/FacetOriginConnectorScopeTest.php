<?php

// Spec 2026-08-26 §6 case 6: the LATENT half of the origin-scope defect, on the
// connector lane, and the flag that gates it.
//
// The manual lane's version of this bug is live because there is exactly ONE
// manual content.sources row per user. The connector lane has the same
// clobbering behaviour and is merely MASKED: a full projection run writes every
// coord of a source together, so the replace deletes once and reinserts the
// union. A run that covers only SOME of an item's coords does not.
//
// Producing such a run is the whole difficulty — projectStream() normally sweeps
// the stream. Dropping one record's is_current is what makes the second run
// partial here.
//
// FAST LANE ON PURPOSE. This is a DELETE-PREDICATE test, not a cascade test:
// nothing is deleted from content.items and no FK fires, so SQLite evaluates
// exactly the same WHERE clause Postgres would. The cascade half of this
// programme lives in tests/Postgres/ (FacetOriginScopeTest, MergeFacetFoldTest)
// because SQLite genuinely cannot reproduce it.

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    // Every connector runs eagerly on connect; under the sync test queue the
    // observer would run it inline and mint the stream row the helpers below
    // insert by hand.
    Bus::fake([RunSourceJob::class]);
});

/**
 * A bandcamp doc with its OWN art, unlike the shared bandcampDoc() helper.
 *
 * Distinct art matters: content.media_assets is UNIQUE (user_id, fingerprint),
 * so two records sharing an art_url resolve to ONE asset and the two coords
 * become indistinguishable — which is the very thing under test.
 */
function focsDoc(string $title, string $slug): array
{
    return [
        'title' => $title,
        'url' => "https://artist.bandcamp.com/album/{$slug}",
        'artist' => 'Some Artist',
        'release_date' => '2025-05-05',
        'art_url' => "https://f4.bcbits.com/img/{$slug}_10.jpg",
        'type' => 'album',
    ];
}

/**
 * Two connector coords on ONE source but DIFFERENT streams, bound to one item,
 * then a run that projects only the first stream.
 *
 * Two streams, not a tombstone. Tombstoning record B RETIRES its coord
 * (retireAbsentSourceItems()), and a retired origin's rows are meant to be
 * reclaimed, not preserved — so that fixture would assert the opposite of the
 * intended behaviour. A second stream leaves coord B LIVE while
 * projectStream('releases') never covers it, which is the real partial run.
 *
 * @return array{0: string, 1: int} [itemId, media rows on it after the partial run]
 */
function focsPartialRerun(bool $scoped): array
{
    config(['partna.content.facet_origin_scope' => $scoped]);

    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/first' => focsDoc('First Album', 'first'),
    ]);

    // A SECOND stream on the SAME source — one content.sources row, so both
    // coords share a source_id and are distinguishable only by origin.
    $otherStreamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $otherStreamId, 'source_id' => $source['id'], 'stream_name' => 'singles',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    landCurrentRecord($otherStreamId, 'album/second', focsDoc('Second Album', 'second'));

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases');
    $writer->projectStream($source, $otherStreamId, 'releases');

    $contentSourceId = DB::table('content.sources')->where('user_id', $userId)->value('id');
    $keptItemId = DB::table('content.items')->where('user_id', $userId)->where('headline_cache', 'First Album')->value('id');
    $otherItemId = DB::table('content.items')->where('user_id', $userId)->where('headline_cache', 'Second Album')->value('id');

    expect($keptItemId)->not->toBeNull()->and($otherItemId)->not->toBeNull()
        ->and(DB::table('content.item_media')->where('item_id', $keptItemId)->count())->toBe(1);

    // Bind both coords to ONE item and carry the second's media across — the
    // state mergeInto() leaves behind.
    DB::table('content.source_items')->where('source_id', $contentSourceId)
        ->where('item_id', $otherItemId)->update(['item_id' => $keptItemId]);
    DB::table('content.item_media')->where('item_id', $otherItemId)->update(['item_id' => $keptItemId]);

    expect(DB::table('content.item_media')->where('item_id', $keptItemId)->count())->toBe(2);

    // Re-run the FIRST stream only. It covers one of the item's two coords; the
    // other is still live, on the same source, and simply not in this run.
    $writer->projectStream($source, $streamId, 'releases');

    return [$keptItemId, DB::table('content.item_media')->where('item_id', $keptItemId)->count()];
}

it('keeps an untouched coord rows when the flag is on and a run covers only one of them', function () {
    [, $count] = focsPartialRerun(scoped: true);

    expect($count)->toBe(2);
});

it('still clobbers them when the flag is off, which is what landing it off means', function () {
    [, $count] = focsPartialRerun(scoped: false);

    // NOT an aspiration — a pin. The connector lane ships on today's behaviour
    // until dev proves the new scoping (spec §3.3, §9). If this ever returns 2
    // with the flag off, the manual-vs-connector gate has leaked: the manual
    // source is identified by content.sources.kind = 'manual', nothing else.
    expect($count)->toBe(1);
});

it('still reclaims rows whose origin coord has been retired', function () {
    config(['partna.content.facet_origin_scope' => true]);

    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/first' => focsDoc('First Album', 'first'),
        'album/second' => focsDoc('Second Album', 'second'),
    ]);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases');

    $contentSourceId = DB::table('content.sources')->where('user_id', $userId)->value('id');
    $keptItemId = DB::table('content.items')->where('user_id', $userId)->where('headline_cache', 'First Album')->value('id');
    $otherItemId = DB::table('content.items')->where('user_id', $userId)->where('headline_cache', 'Second Album')->value('id');

    DB::table('content.source_items')->where('source_id', $contentSourceId)
        ->where('item_id', $otherItemId)->update(['item_id' => $keptItemId]);
    DB::table('content.item_media')->where('item_id', $otherItemId)->update(['item_id' => $keptItemId]);

    // Tombstone the second record. retireAbsentSourceItems() soft-retires its
    // coord on the next run, and a retired coord's facets must go with it.
    DB::table('ingest.record_state')->where('stream_id', $streamId)->where('key', 'album/second')
        ->update(['tombstoned_at' => now()]);

    $writer->projectStream($source, $streamId, 'releases');

    // Scoping the delete to the COVERED origins alone would strand this row
    // forever: its origin matches neither the IS NULL half nor the covered set,
    // and PoolResolver reads content.item_media by item_id with no source-item
    // liveness filter, so the photo would render for good. The unscoped delete
    // used to sweep it, and the predicate is written to preserve rather than to
    // delete precisely so it still does.
    expect(DB::table('content.source_items')->where('source_id', $contentSourceId)->whereNotNull('removed_at')->count())->toBe(1)
        ->and(DB::table('content.item_media')->where('item_id', $keptItemId)->count())->toBe(1);
});
