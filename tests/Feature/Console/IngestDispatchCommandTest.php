<?php

// Feature tests for `ingest:dispatch` (OBS-2): previously always returned
// SUCCESS regardless of outcome, so a tick that claimed sources and dispatched
// NONE of them looked identical to a clean run. Uses --sync so a bad row's
// exception surfaces inline (per the command's own comment at the catch site)
// rather than silently vanishing onto the queue.
//
// Report-count note: a --sync dispatch that throws is ALREADY reported twice
// today, independent of this fix — RunSourceJob's own finally releases the
// claim and rethrows, and Laravel's SyncQueue then calls the job's failed()
// hook (a defensive backstop for a hard kill, per its own docblock) BEFORE
// this command's catch runs, so RunSourceJob::failed() reports it once and
// the command's per-source catch reports it again. That pre-existing
// duplication is out of scope here. What Unit F adds is the ONE aggregate
// IngestDispatchStalledException on total failure — so these tests assert
// that specific exception's presence/absence, not a total report count.

use App\Exceptions\Ingest\IngestDispatchStalledException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
});

/** ingest.sources row due right now, defaulted to a registered (bandcamp) connector. */
function seedDueIngestSource(string $sourceKey = 'bandcamp', ?string $identifier = null): string
{
    $id = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'source_key' => $sourceKey,
        'surface_key' => 'music',
        'identifier' => $identifier ?? 'source-'.$id,
        'cost_units' => 1,
        'visibility' => 1.0,
        'change_rate' => 0.5,
        'next_attempt_at' => now()->subMinute(),
        'last_run_at' => null,
    ]);

    return $id;
}

it('a clean tick exits 0 and reports nothing', function () {
    Exceptions::fake();
    // RunExecutor catches the fetch failure internally and marks the stream
    // 'error' without throwing — the dispatch itself still succeeds.
    Http::fake(['*' => Http::response('', 500)]);

    seedDueIngestSource();

    $this->artisan('ingest:dispatch', ['--sync' => true])->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('an empty tick (nothing due) exits 0 and reports nothing', function () {
    Exceptions::fake();

    $this->artisan('ingest:dispatch', ['--sync' => true])->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('a partial failure exits non-zero and does NOT add the aggregate stalled exception', function () {
    Exceptions::fake();
    Http::fake(['*' => Http::response('', 500)]);

    seedDueIngestSource('bandcamp', 'good-1');
    seedDueIngestSource('bandcamp', 'good-2');
    // Unregistered source_key -> ConnectorRegistry::for() throws before any
    // fetch happens, escaping RunSourceJob::handle() and this command's catch.
    seedDueIngestSource('nonexistent_connector', 'bad-1');

    $this->artisan('ingest:dispatch', ['--sync' => true])->assertExitCode(1);

    // "One of three had a bad row" must not ALSO produce the "the tick
    // achieved nothing" aggregate — that would be a literal duplicate of
    // information the per-source report already carries.
    Exceptions::assertNotReported(IngestDispatchStalledException::class);
});

it('a total failure exits non-zero and reports the aggregate exception naming the claimed count', function () {
    Exceptions::fake();

    seedDueIngestSource('nonexistent_connector', 'bad-1');
    seedDueIngestSource('nonexistent_connector', 'bad-2');
    seedDueIngestSource('nonexistent_connector', 'bad-3');

    $this->artisan('ingest:dispatch', ['--sync' => true])->assertExitCode(1);

    Exceptions::assertReported(fn (IngestDispatchStalledException $e) => $e->claimed === 3);
});
