<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// End-to-end sync shapes (plan 2026-08-25 Task 7b). Tasks 1 and 4 prove the
// closure rule and old-equals-new; this file asks the blunter question — does a
// real projection run still group a realistic catalogue correctly with the
// narrowing on? Each test is one duplicate shape.
//
// Run with the narrowing ON deliberately: these are the cases it could break.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Bus::fake([RunSourceJob::class]);
    config(['partna.content.identity_scope' => true]);
});

/** Every content.items row for the user, with the coords bound to it. */
function coordsByItem(string $userId): array
{
    $rows = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->whereNull('si.removed_at')
        ->get(['si.coord', 'si.item_id']);

    $out = [];
    foreach ($rows as $row) {
        // A NULL item_id means resolveItemsLocked() never bound this coord.
        // Grouping every such row into a shared "" bucket would let two
        // never-bound coords masquerade as "merged into one item" to a
        // toHaveCount(1) assertion below — the exact same shape a real merge
        // produces. Every fixture in this file expects a completed
        // projection run to leave nothing unbound, so treat it as a hard
        // failure instead of a silent bucket.
        if ($row->item_id === null) {
            throw new RuntimeException("coordsByItem(): coord {$row->coord} has no item_id — resolution left it unbound.");
        }
        $out[(string) $row->item_id][] = (string) $row->coord;
    }
    foreach ($out as $itemId => $coords) {
        sort($coords);
        $out[$itemId] = $coords;
    }

    return $out;
}

it('leaves a lone item as one item', function () {
    [$userId, , $source, $stream] = projectableBandcamp([
        'r1' => bandcampDoc('Only Release', 'https://a.bandcamp.com/album/only'),
    ]);

    app(ProjectionWriter::class)->projectStream($source, $stream, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);
});

it('merges two sources carrying the same release into one item', function () {
    $url = 'https://a.bandcamp.com/album/shared';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Shared', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Shared', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $byItem = coordsByItem($userId);

    expect($byItem)->toHaveCount(1)
        ->and(reset($byItem))->toHaveCount(2);
});

it('keeps two same-source releases apart despite a shared title', function () {
    // A single source listing the same title twice is real corroborating
    // evidence — but Resolver::unionAll() requires cross-source for the
    // Corroborating tier precisely so one platform's own two listings never
    // merge on a title alone (a discography's two "Live" pressings, say).
    //
    // The identical title within ONE source ALSO poisons that signature
    // (Resolver::poisonedKeys()) — a same-source duplicate of a value is, by
    // construction, exactly what poisons it. Both mechanisms fire on this
    // fixture and either one alone is enough to keep the pair apart, which
    // was verified empirically by mutation (restored before commit, see
    // task-7b-report.md): stubbing `requireCrossSource: true` to `false`
    // alone leaves this test GREEN (poisonedKeys() still drops the
    // signature); stubbing `poisonedKeys()` to return `[]` alone ALSO leaves
    // it green (requireCrossSource still blocks the same-source pair). Only
    // disabling BOTH in Resolver::resolve() (app/Content/Identity/Resolver.php)
    // at once merges them and turns this test red. That is the real,
    // confirmed falsifier — not either guard in isolation — and it is why
    // this test cannot be used to pin `requireCrossSource` specifically; the
    // poisoned-key test elsewhere in this file is what pins poisonedKeys()
    // with a fixture that isolates it (a cross-source pair unaffected by
    // requireCrossSource).
    $title = 'Wandering Under Northern Skies';

    [$userId, , $source, $stream] = projectableBandcamp([
        'r1' => bandcampDoc($title, 'https://a.bandcamp.com/album/wandering-1'),
        'r2' => bandcampDoc($title, 'https://a.bandcamp.com/album/wandering-2'),
    ]);

    app(ProjectionWriter::class)->projectStream($source, $stream, 'releases');

    expect(coordsByItem($userId))->toHaveCount(2);
});

it('collapses a few sources sharing one release into a single item', function () {
    $url = 'https://a.bandcamp.com/album/everywhere';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Everywhere', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Everywhere', $url)], $userId);
    [, , $sourceC, $streamC] = projectableBandcamp(['r1' => bandcampDoc('Everywhere', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');
    $writer->projectStream($sourceC, $streamC, 'releases');

    $byItem = coordsByItem($userId);

    expect($byItem)->toHaveCount(1)
        ->and(reset($byItem))->toHaveCount(3);
});

it('does NOT merge when one source lists the same thing twice — the poisoned key', function () {
    // Guard 2 through a real sync (plan §A.1): the closure indexes signatures
    // UNFILTERED, so B's second copy is present for poisonedKeys() to see.
    // Source B carries the release TWICE under two record keys; that duplicate
    // poisons the corroborating key, so A and B must stay apart. If the closure
    // dropped the weak signature, the key would look clean and these would
    // merge — and mergeInto() hard-deletes the loser.
    //
    // The poisoning sibling is ONE hop from the touched coord (it shares the
    // key), so this is not the transitivity case; the chained-union tests cover
    // that. Keeping the two guards distinct matters — conflating them is the
    // error the plan's own first draft made.
    [$userId, , $sourceA, $streamA] = projectableBandcamp([
        'r1' => bandcampDoc('Twice Listed', 'https://a.bandcamp.com/album/twice-listed'),
    ]);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'r1' => bandcampDoc('Twice Listed', 'https://b.bandcamp.com/album/twice-listed-single'),
        'r2' => bandcampDoc('Twice Listed', 'https://b.bandcamp.com/album/twice-listed-album'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $byItem = coordsByItem($userId);

    // Three coords, and A must not share an item with either of B's.
    expect(array_sum(array_map('count', $byItem)))->toBe(3)
        ->and($byItem)->toHaveCount(3);
});

it('gives the same grouping with the narrowing off', function () {
    // The whole file, re-run the old way. Any shape above that depends on the
    // narrowing rather than surviving it shows up here as a difference.
    $url = 'https://a.bandcamp.com/album/parity';

    $run = function (bool $scoped) use ($url) {
        config(['partna.content.identity_scope' => $scoped]);

        [$userId, $connA, $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Parity', $url)]);
        [, $connB, $sourceB, $streamB] = projectableBandcamp([
            'r1' => bandcampDoc('Parity', $url),
            'r2' => bandcampDoc('Parity Other', 'https://b.bandcamp.com/album/parity-other'),
        ], $userId);

        $writer = app(ProjectionWriter::class);
        $writer->projectStream($sourceA, $streamA, 'releases');
        $writer->projectStream($sourceB, $streamB, 'releases');

        // Shape only — item ids are freshly minted uuids in each run, AND
        // projectableBandcamp() mints a fresh random connection resource_id on
        // every call, so the raw coord strings differ between this run and
        // the other even when the grouping is identical. Normalise each coord
        // to its role (which connection, which record key) rather than
        // comparing the random account segment.
        $label = fn (string $coord) => (str_starts_with($coord, "bandcamp:{$connA->resource_id}:") ? 'A:' : 'B:')
            .substr($coord, (int) strrpos($coord, ':') + 1);

        $shape = array_values(array_map(
            fn (array $coords) => collect($coords)->map($label)->sort()->values()->all(),
            coordsByItem($userId)
        ));
        sort($shape);

        return $shape;
    };

    expect($run(true))->toBe($run(false));
});

it('follows an owner "same" ruling that no shared key would have found', function () {
    // A decision edge is the one closure edge with no key behind it. If the
    // component walk ignores decisions, these two stop merging.
    [$userId, , $sourceA, $streamA] = projectableBandcamp([
        'r1' => bandcampDoc('Alpha', 'https://a.bandcamp.com/album/alpha'),
    ]);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'r1' => bandcampDoc('Beta', 'https://b.bandcamp.com/album/beta'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(2);

    // The owner overrules: same thing. Coords are stored sorted.
    $coords = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)->orderBy('si.coord')->pluck('si.coord')->all();

    // content.identity_decisions has no created_at column — decided_at is the
    // timestamp (DEFAULT now() in Postgres, but the SQLite Feature-lane
    // stand-in has no default, so it must be supplied), and id has no default
    // in the stand-in either (supabase/migrations/20260727140000_content_schema.sql,
    // tests/Pest.php setupContentTables()).
    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'left_coord' => $coords[0],
        'right_coord' => $coords[1],
        'verdict' => 'same',
        'decided_at' => now(),
    ]);

    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);
});

it('honours an owner "different" ruling over a joining key', function () {
    // A cut must beat a shared url. If the narrowed decision list drops the
    // cut, two things the owner deliberately separated silently re-merge.
    //
    // Deliberately NOT built as "merge them, then cut" — anchors are STICKY
    // (see the last test in this file), so a decision arriving AFTER a real
    // merge does not un-merge the existing anchor either way and would prove
    // nothing about the cut. The cut has to be in place BEFORE the shared key
    // shows up, so this drives A and B apart on distinct urls first, records
    // the cut, and only then moves B's url to collide with A's.
    [$userId, , $sourceA, $streamA] = projectableBandcamp([
        'r1' => bandcampDoc('Contested', 'https://a.bandcamp.com/album/contested'),
    ]);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'r1' => bandcampDoc('Contested Other', 'https://b.bandcamp.com/album/contested-other'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(2);

    $coords = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)->orderBy('si.coord')->pluck('si.coord')->all();

    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'left_coord' => $coords[0],
        'right_coord' => $coords[1],
        'verdict' => 'different',
        'decided_at' => now(),
    ]);

    // Now B's release moves to A's url — a fresh joining-key collision the
    // cut must survive.
    DB::table('ingest.record_versions')->where('stream_id', $streamB)->delete();
    DB::table('ingest.record_state')->where('stream_id', $streamB)->delete();
    landCurrentRecord($streamB, 'r1', bandcampDoc('Contested', 'https://a.bandcamp.com/album/contested'));

    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(2);
});

it('ignores a source whose connection the owner removed', function () {
    // disconnect = hide. A removed connection's items must not vote on
    // identity — this shipped as a live bug once: an old Apple connection's
    // FIVE compilation copies of one song poisoned the title|artist key and
    // kept a live Spotify <-> Apple pair apart, even though neither Spotify
    // nor Apple item was itself duplicated anywhere.
    //
    // The disconnected source below carries the SAME title twice under two
    // urls — that duplicate is what would poison the title corroborating key
    // if this source were still counted. Two LIVE sources each carry ONE item
    // with that same title and no shared url, so the title is the only thing
    // that could join them.
    //
    // Falsifier: neutralise the $liveSource filter in
    // ProjectionWriter::resolveItemsLocked() (app/Ingest/Projection/ProjectionWriter.php,
    // e.g. `$liveSource = fn ($q) => $q;`) and this fails — verified
    // empirically (mutation run, restored before commit; see
    // task-7b-report.md). The disconnected source's rows get read back into
    // $sourceItems, poisonedKeys() sees the title repeated within that one
    // source, the title signature is poisoned GLOBALLY (Resolver::poisonedKeys()
    // keys poison by signature value alone, not per-source), the live pair
    // stops sharing any usable key, and A's and B's item ids come back
    // different instead of equal.
    $title = 'Chasing Distant Mountains';

    [$userId, $connX, $sourceX, $streamX] = projectableBandcamp([
        'r1' => bandcampDoc($title, 'https://x.bandcamp.com/album/chasing-1'),
        'r2' => bandcampDoc($title, 'https://x.bandcamp.com/album/chasing-2'),
    ]);
    app(ProjectionWriter::class)->projectStream($sourceX, $streamX, 'releases');
    $connX->delete(); // soft delete — disconnect = hide

    [, $connA, $sourceA, $streamA] = projectableBandcamp([
        'r1' => bandcampDoc($title, 'https://a.bandcamp.com/album/chasing'),
    ], $userId);
    [, $connB, $sourceB, $streamB] = projectableBandcamp([
        'r1' => bandcampDoc($title, 'https://b.bandcamp.com/album/chasing'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    // The two LIVE items merge on the shared title — which they can only do
    // if the disconnected source's duplicate copies were excluded from the
    // poison calculation. Look up A's and B's own item ids directly by
    // CONNECTION (content.sources.id is a distinct id from ingest.sources.id
    // — content.sources.connection_id is what ties back to the connection
    // projectableBandcamp() returns) rather than re-deriving the writer's
    // connection filter: this only passes if the WRITER actually excluded
    // source X, not because the assertion repeats its predicate.
    $contentSourceA = DB::table('content.sources')->where('connection_id', $connA->id)->value('id');
    $contentSourceB = DB::table('content.sources')->where('connection_id', $connB->id)->value('id');
    $contentSourceX = DB::table('content.sources')->where('connection_id', $connX->id)->value('id');

    $itemA = DB::table('content.source_items')->where('source_id', $contentSourceA)->value('item_id');
    $itemB = DB::table('content.source_items')->where('source_id', $contentSourceB)->value('item_id');
    $itemsX = DB::table('content.source_items')->where('source_id', $contentSourceX)->pluck('item_id');

    expect($itemA)->not->toBeNull()
        ->and($itemA)->toBe($itemB)
        ->and($itemsX->contains($itemA))->toBeFalse();
});

it('is idempotent — a second identical sync mints nothing and moves nothing', function () {
    $url = 'https://a.bandcamp.com/album/stable';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Stable', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Stable', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $first = coordsByItem($userId);
    $itemIds = DB::table('content.items')->where('user_id', $userId)->orderBy('id')->pluck('id')->all();

    // Run both streams again, unchanged.
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toBe($first)
        ->and(DB::table('content.items')->where('user_id', $userId)->orderBy('id')->pluck('id')->all())
        ->toBe($itemIds);
});

it('merges an owner-added item into the scraped one it matches', function () {
    // writeManualItem()'s own path — #CACHE-2's actual surface. It now resolves
    // a component seeded from a SINGLE coord, so if the seed does not reach the
    // scraped twin, the owner sees a duplicate of their own item.
    $url = 'https://a.bandcamp.com/album/handmade';

    [$userId, , $source, $stream] = projectableBandcamp(['r1' => bandcampDoc('Handmade', $url)]);
    app(ProjectionWriter::class)->projectStream($source, $stream, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);

    // The owner adds the same release by hand, through the real manual-item
    // seam. ensureManualSource()/writeManualItem() are the same public methods
    // manualSourceFor()/projectOne() (ProjectionWriterTest.php) wrap — those
    // helpers mint a FRESH tenant, so calling them directly here would test
    // the wrong user. This drives the identical writer path against the
    // user who already has the scraped release.
    $manualSourceId = app(ProjectionWriter::class)->ensureManualSource($userId);
    projectOne($manualSourceId, $userId, 'manual:handmade', [
        'kind' => 'release',
        'headline' => 'Handmade',
        'facets' => ['f_link' => ['url' => $url]],
    ]);

    // The assertion is the point: still ONE item, now with two coords.
    $byItem = coordsByItem($userId);

    expect($byItem)->toHaveCount(1)
        ->and(reset($byItem))->toHaveCount(2);
});

it('does not auto-split a merged pair whose keys stop matching', function () {
    // Anchors are STICKY: once bound, a pair does not un-merge just because the
    // evidence changed. That is pre-existing behaviour, and this pins that the
    // narrowing did not accidentally change it in either direction.
    $url = 'https://a.bandcamp.com/album/drift';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Drift', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Drift', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);

    // B's release moves to a different url — the joining key no longer matches.
    DB::table('ingest.record_versions')->where('stream_id', $streamB)->delete();
    DB::table('ingest.record_state')->where('stream_id', $streamB)->delete();
    landCurrentRecord($streamB, 'r1', bandcampDoc('Drift', 'https://b.bandcamp.com/album/drift-moved'));

    $writer->projectStream($sourceB, $streamB, 'releases');

    // Still one item. If this ever becomes two, that is a deliberate product
    // change, not a passing test to update quietly.
    expect(coordsByItem($userId))->toHaveCount(1);
});
