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
 * Two connector coords bound to ONE item (as a merge leaves them), then a
 * SECOND run that covers only the first coord.
 *
 * @return array{0: string, 1: int} [itemId, media rows on it after the partial run]
 */
function focsPartialRerun(bool $scoped): array
{
    config(['partna.content.facet_origin_scope' => $scoped]);

    [$userId, , $source, $streamId] = projectableBandcamp([
        'album/first' => focsDoc('First Album', 'first'),
        'album/second' => focsDoc('Second Album', 'second'),
    ]);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases');

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

    // Tombstone the second record, so the next run covers ONE of the item's two
    // coords. Without this, projectStream() writes both and the replace
    // reinserts the union — which is exactly why this bug has stayed hidden.
    // It must be tombstoned_at on ingest.record_state, not is_current on the
    // VERSION: projectStream() joins record_state to its current_version_id and
    // filters `rs.tombstoned_at IS NULL`, so clearing is_current changes nothing
    // and the run silently stays whole — a fixture that proves nothing.
    DB::table('ingest.record_state')->where('stream_id', $streamId)->where('key', 'album/second')
        ->update(['tombstoned_at' => now()]);

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
