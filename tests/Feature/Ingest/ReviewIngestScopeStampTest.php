<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// The write half of the storewide blocker (2026-09-01). A review's scope is a
// fact about its INGESTION — which team member the vendor feed was narrowed to
// when the connector landed it — and until now nothing recorded it, so
// PoolResolver's employee-scope gate read the source's CURRENT selection and
// retroactively re-labelled a whole salon's storewide corpus as one employee's.
// content.source_items.ingest_selection_ref is that record; these pin who is
// allowed to write it.
//
// PoolResolverPersonScopeTest pins what the pool does with the value. This file
// pins that the value is true.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    // Connectors run eagerly on connect; the projection seam is driven directly.
    Bus::fake([RunSourceJob::class]);
    Queue::fake();
});

/**
 * A Fresha connection whose ingest source is scoped to $selectionRef, with one
 * landed review record. Returns [ingest source row, stream id].
 *
 * @return array{array<string, mixed>, string}
 */
function rissFreshaSource(string $selectionRef, string $employeeName = 'Raff'): array
{
    $userId = (string) createTenant('riss-'.Str::lower(Str::random(6)))->id;
    $connectionId = poolConnection($userId, 'fresha.book');

    $sourceId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'connection_id' => $connectionId,
        'source_key' => 'fresha', 'surface_key' => 'fresha.book',
        'identifier' => 'vision-hair-studio-melbourne-tzo6gxk0',
        'selection_ref' => $selectionRef, 'cost_units' => 1,
        'min_interval_secs' => 3600, 'max_interval_secs' => 604800,
        'auto_sync' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $sourceId, 'stream_name' => 'reviews',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    landCurrentRecord($streamId, 'r:1', [
        'rating' => 5, 'text' => 'Best cut in Melbourne.',
        'author' => 'A Guest', 'publish_time' => '2026-08-01T10:00:00Z',
        'employee_name' => $employeeName,
    ]);

    return [(array) DB::table('ingest.sources')->where('id', $sourceId)->first(), $streamId];
}

/** What the one landed source item says it was ingested under. */
function rissStoredScope(): mixed
{
    return DB::table('content.source_items')->where('kind', 'review')->value('ingest_selection_ref');
}

it('stamps a review with the vendor selection the run that fetched it was scoped to', function () {
    [$source, $streamId] = rissFreshaSource('5035183');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', recordsFetchedThisRun: true);

    expect(rissStoredScope())->toBe('5035183');
});

it('stamps a storewide harvest as storewide, which is the claim it actually made', function () {
    [$source, $streamId] = rissFreshaSource('storewide');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', recordsFetchedThisRun: true);

    expect(rissStoredScope())->toBe('storewide');
});

it('leaves a review\'s ingest scope alone when the run fetched nothing', function () {
    // THE blocker, at the seam that would reopen it. `ingest:project` re-derives
    // content from the landed record log without fetching a byte, so it has no
    // ingestion to report — and stamping the source's PRESENT selection here
    // would re-label a storewide corpus as one employee's without a single new
    // record arriving, which is the entire defect wearing a repair command's
    // clothes.
    [$source, $streamId] = rissFreshaSource('storewide');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', recordsFetchedThisRun: true);
    expect(rissStoredScope())->toBe('storewide');

    // The owner narrows the connection to themselves. No harvest follows.
    DB::table('ingest.sources')->where('id', $source['id'])->update(['selection_ref' => '5035183']);
    $narrowed = (array) DB::table('ingest.sources')->where('id', $source['id'])->first();

    app(ProjectionWriter::class)->projectStream($narrowed, $streamId, 'reviews');

    expect(rissStoredScope())->toBe('storewide');
});

it('restates a review\'s ingest scope once a run actually re-fetches it', function () {
    // The other direction: a connection genuinely narrowed AND re-harvested
    // lands these reviews under the new selection, and its rows say so from
    // then on. Without this the column would be write-once and a real
    // narrowing could never take effect.
    [$source, $streamId] = rissFreshaSource('storewide');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', recordsFetchedThisRun: true);

    DB::table('ingest.sources')->where('id', $source['id'])->update(['selection_ref' => '5035183']);
    $narrowed = (array) DB::table('ingest.sources')->where('id', $source['id'])->first();

    app(ProjectionWriter::class)->projectStream($narrowed, $streamId, 'reviews', recordsFetchedThisRun: true);

    expect(rissStoredScope())->toBe('5035183');
});

it('clears a review\'s ingest scope when a fetching run has no vendor selection left', function () {
    // A run that fetched writes the answer WHOLE, null included. A source whose
    // picker was cleared is no longer claiming employee scope, and inheriting
    // the old employee id would be the storewide bug with the arrows reversed.
    [$source, $streamId] = rissFreshaSource('5035183');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', recordsFetchedThisRun: true);
    expect(rissStoredScope())->toBe('5035183');

    DB::table('ingest.sources')->where('id', $source['id'])->update(['selection_ref' => null]);
    $cleared = (array) DB::table('ingest.sources')->where('id', $source['id'])->first();

    app(ProjectionWriter::class)->projectStream($cleared, $streamId, 'reviews', recordsFetchedThisRun: true);

    expect(rissStoredScope())->toBeNull();
});

it('records no ingest scope for a review a re-projection sees for the first time', function () {
    // A first-sight row from a lane that fetched nothing is a row we have no
    // ingestion for, and unknown scope is not employee scope.
    [$source, $streamId] = rissFreshaSource('5035183');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    expect(DB::table('content.source_items')->where('kind', 'review')->count())->toBe(1)
        ->and(rissStoredScope())->toBeNull();
});
