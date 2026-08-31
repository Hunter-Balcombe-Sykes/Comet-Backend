<?php

use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Record;
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
// SECOND PASS. The first fix made "may I stamp?" a property of the RUN, and
// projectStream() sweeps the stream's whole live record log — so one new review
// arriving under a narrowed selection restamped every storewide row the feed
// had stopped returning. The defect the column exists to end, reached through
// its own guard. It is per-RECORD now (ingest.record_state.last_seen_run), so
// every case below LANDS through the real Lander under a named run rather than
// asserting a boolean: what a run fetched is the whole question, and a test
// that never fetches cannot ask it.
//
// PoolResolverPersonScopeTest pins what the pool does with the value. This file
// pins that the value is true. ReviewIngestScopeProvenanceTest pins it as a
// property over arbitrary run sequences.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    // Connectors run eagerly on connect; the projection seam is driven directly.
    Bus::fake([RunSourceJob::class]);
    Queue::fake();
});

/** The Fresha reviews stream spec, as the manifest declares it. */
function rissSpec(): StreamSpec
{
    return new StreamSpec(name: 'reviews', target: 'review', profile: SourceProfile::Sample);
}

/** One Fresha review doc; $employeeName is the vendor's structured attribution. */
function rissDoc(string $text = 'Best cut in Melbourne.', string $employeeName = 'Raff'): array
{
    return [
        'rating' => 5, 'text' => $text,
        'author' => 'A Guest', 'publish_time' => '2026-08-01T10:00:00Z',
        'employee_name' => $employeeName,
    ];
}

/**
 * A run of the connector: $docs (key => doc) are what the vendor feed RETURNED
 * this time, landed durably under $runId. Coverage is null on purpose — a
 * Sample stream can never conclude deletion from absence, so the keys this run
 * did not return stay live in the log and stay in projectStream()'s sweep,
 * which is precisely the situation the blocker lives in.
 */
function rissLand(string $streamId, string $runId, array $docs): void
{
    app(Lander::class)->land(
        streamId: $streamId,
        runId: $runId,
        spec: rissSpec(),
        records: array_map(
            static fn (string $key, array $doc): Record => new Record('reviews', $key, $doc),
            array_map('strval', array_keys($docs)),
            array_values($docs),
        ),
        covered: null,
    );
}

/**
 * A Fresha connection whose ingest source is scoped to $selectionRef, with one
 * review ('r:1') already landed by run $runId. Returns [ingest source row,
 * stream id].
 *
 * @return array{array<string, mixed>, string}
 */
function rissFreshaSource(string $selectionRef, string $runId, string $employeeName = 'Raff'): array
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

    rissLand($streamId, $runId, ['r:1' => rissDoc(employeeName: $employeeName)]);

    return [(array) DB::table('ingest.sources')->where('id', $sourceId)->first(), $streamId];
}

/** Re-read the source row after its selection was changed. */
function rissReselect(array $source, ?string $selectionRef): array
{
    DB::table('ingest.sources')->where('id', $source['id'])->update(['selection_ref' => $selectionRef]);

    return (array) DB::table('ingest.sources')->where('id', $source['id'])->first();
}

/** What the source item for $recordKey says it was ingested under. */
function rissStoredScope(string $recordKey = 'r:1'): mixed
{
    return DB::table('content.source_items')
        ->where('kind', 'review')->where('record_key', $recordKey)
        ->value('ingest_selection_ref');
}

it('stamps a review with the vendor selection the run that fetched it was scoped to', function () {
    [$source, $streamId] = rissFreshaSource('5035183', 'run-1');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: 'run-1');

    expect(rissStoredScope())->toBe('5035183');
});

it('stamps a storewide harvest as storewide, which is the claim it actually made', function () {
    [$source, $streamId] = rissFreshaSource('storewide', 'run-1');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: 'run-1');

    expect(rissStoredScope())->toBe('storewide');
});

it('leaves a review\'s ingest scope alone when the run fetched nothing', function () {
    // `ingest:project` re-derives content from the landed record log without
    // fetching a byte, so it has no ingestion to report — and stamping the
    // source's PRESENT selection here would re-label a storewide corpus as one
    // employee's without a single new record arriving, which is the entire
    // defect wearing a repair command's clothes.
    [$source, $streamId] = rissFreshaSource('storewide', 'run-1');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: 'run-1');
    expect(rissStoredScope())->toBe('storewide');

    // The owner narrows the connection to themselves. No harvest follows.
    $narrowed = rissReselect($source, '5035183');

    app(ProjectionWriter::class)->projectStream($narrowed, $streamId, 'reviews');

    expect(rissStoredScope())->toBe('storewide');
});

it('restates a review\'s ingest scope once a run actually re-fetches it', function () {
    // The other direction, and this test now does what its name says. It used
    // to change the selection, project again, and assert the new value —
    // without re-landing a single record. It passed because the stamp was a
    // property of the run, which is exactly the defect; it PINNED that defect
    // as intended behaviour, and this is the shape that tells them apart.
    // Without a genuine re-fetch the column would be write-once and a real
    // narrowing could never take effect, so the case is worth keeping — it just
    // has to fetch.
    [$source, $streamId] = rissFreshaSource('storewide', 'run-1');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: 'run-1');
    expect(rissStoredScope())->toBe('storewide');

    $narrowed = rissReselect($source, '5035183');
    // The narrowed feed still returns this review — it is genuinely this
    // employee's — so run-2's fetch re-lands it under the new selection.
    rissLand($streamId, 'run-2', ['r:1' => rissDoc('Still the best cut in Melbourne.')]);

    app(ProjectionWriter::class)->projectStream($narrowed, $streamId, 'reviews', fetchedInRunId: 'run-2');

    expect(rissStoredScope())->toBe('5035183');
});

it('leaves the storewide corpus alone when a narrowed run re-fetches only part of it', function () {
    // THE BLOCKER, in the shape the auditor reproduced on a real person's page.
    // projectStream() sweeps every LIVE record in the stream, not the slice the
    // run just landed, and a Sample stream never tombstones on absence — so the
    // storewide reviews the narrowed feed stopped returning are still swept on
    // every pass. A per-run stamp flag rewrote all of them from one new arrival.
    [$source, $streamId] = rissFreshaSource('storewide', 'run-1');
    rissLand($streamId, 'run-1', [
        'r:2' => rissDoc('Ciel was amazing.', 'Ciel'),
        'r:3' => rissDoc('Lovely colour work.', 'Bea'),
    ]);
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: 'run-1');

    // Narrowed to employee 5035183. The next harvest returns ONE review — the
    // employee's own new one. r:2 and r:3 are other stylists' work and the
    // vendor no longer sends them; they simply sit in the log.
    $narrowed = rissReselect($source, '5035183');
    rissLand($streamId, 'run-2', ['r:4' => rissDoc('Sharpest fade I have had.')]);

    app(ProjectionWriter::class)->projectStream($narrowed, $streamId, 'reviews', fetchedInRunId: 'run-2');

    expect(rissStoredScope('r:4'))->toBe('5035183')
        ->and(rissStoredScope('r:1'))->toBe('storewide')
        ->and(rissStoredScope('r:2'))->toBe('storewide')
        ->and(rissStoredScope('r:3'))->toBe('storewide');
});

it('clears a review\'s ingest scope when a fetching run has no vendor selection left', function () {
    // A run that fetched writes the answer WHOLE, null included. A source whose
    // picker was cleared is no longer claiming employee scope, and inheriting
    // the old employee id would be the storewide bug with the arrows reversed.
    [$source, $streamId] = rissFreshaSource('5035183', 'run-1');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: 'run-1');
    expect(rissStoredScope())->toBe('5035183');

    $cleared = rissReselect($source, null);
    rissLand($streamId, 'run-2', ['r:1' => rissDoc('Cut number two.')]);

    app(ProjectionWriter::class)->projectStream($cleared, $streamId, 'reviews', fetchedInRunId: 'run-2');

    expect(rissStoredScope())->toBeNull();
});

it('records no ingest scope for a review a re-projection sees for the first time', function () {
    // A first-sight row from a lane that fetched nothing is a row we have no
    // ingestion for, and unknown scope is not employee scope.
    [$source, $streamId] = rissFreshaSource('5035183', 'run-1');

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    expect(DB::table('content.source_items')->where('kind', 'review')->count())->toBe(1)
        ->and(rissStoredScope())->toBeNull();
});

it('records no ingest scope for a record no run has ever been credited with fetching', function () {
    // Provenance is read, never assumed. A record_state row with a null
    // last_seen_run — landed before the column meant anything, or repaired in
    // by hand — is a record we cannot speak for, and a fetching run sweeping
    // past it must not lend it the selection it is carrying.
    [$source, $streamId] = rissFreshaSource('5035183', 'run-1');
    DB::table('ingest.record_state')->where('stream_id', $streamId)->update(['last_seen_run' => null]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: 'run-1');

    expect(rissStoredScope())->toBeNull();
});

it('does not read a re-projection and an unrecorded ingestion as the same run', function () {
    // Two absences of knowledge, and the writer must not mistake them for a
    // match. `ingest:project` reports no run; a record whose provenance was
    // never recorded reports no run. Compared without a guard those two nulls
    // are equal, and the whole storewide corpus would be stamped with whatever
    // the picker happens to say today by a command that fetched nothing at all.
    [$source, $streamId] = rissFreshaSource('5035183', 'run-1');
    DB::table('ingest.record_state')->where('stream_id', $streamId)->update(['last_seen_run' => null]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews');

    expect(rissStoredScope())->toBeNull();
});
