<?php

// The ingest lane reports its run result back onto the connection row.
// Before this, a router-placed connection ('pending' from SourceReconciler)
// stayed 'pending' forever: no writer under app/Ingest touched
// last_refresh_status, and the legacy ConnectFetchJob/PlatformRefresher lane
// is never dispatched on that path (gsnwilliams / Fresha, 2026-08-18).

use App\Ingest\Runtime\IngestStatusWriteback;
use App\Ingest\Runtime\RunExecutor;
use App\Ingest\Runtime\SourceScheduler;
use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
});

function wbUser(): string
{
    return createTenant('wb-'.Str::lower(Str::random(6)))->id;
}

/** A connection + its ingest source, seeded directly (no observer/eager run). */
function wbSourceFor(string $userId, string $status, string $sourceKey = 'no_such_connector'): array
{
    $connection = IntegrationConnection::withoutEvents(fn () => IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'fresha',
        'resource_id' => 'anseo-'.Str::lower(Str::random(4)),
        'payload' => ['url' => 'https://www.fresha.com/a/anseo', 'source' => 'link_in_bio'],
        'is_active' => true,
        'last_refresh_status' => $status,
    ]));

    $sourceId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $sourceId,
        'user_id' => $userId,
        'connection_id' => $connection->id,
        'source_key' => $sourceKey,
        'surface_key' => 'fresha.book',
        'identifier' => 'anseo',
        'cost_units' => 1,
        'min_interval_secs' => 3600,
        'max_interval_secs' => 604800,
        'change_rate' => 0.5,
        'next_attempt_at' => now()->subMinute(),
        'visibility' => 1.0,
        'in_flight_since' => now(),
        'in_flight_run_id' => 'run-1',
        'health' => 'ok',
        'consecutive_failures' => 0,
        'auto_sync' => true,
        'scope' => 'all',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$connection, $sourceId];
}

function wbRun(string $sourceId, string $outcome, array $notes = []): string
{
    $runId = (string) Str::uuid();
    DB::table('ingest.runs')->insert([
        'id' => $runId,
        'source_id' => $sourceId,
        'trigger' => 'schedule',
        'started_at' => now(),
        'finished_at' => now(),
        'outcome' => $outcome,
        'records_seen' => 0,
        'records_changed' => 0,
        'records_tombstoned' => 0,
        'effects_count' => 0,
        'cost_claimed' => 0,
        'detail' => json_encode(['streams' => [], 'notes' => $notes]),
        'created_at' => now(),
    ]);

    return $runId;
}

it('flips a pending connection to ok after a clean run', function () {
    [$connection, $sourceId] = wbSourceFor(wbUser(), 'pending');
    $runId = wbRun($sourceId, 'ok');

    app(IngestStatusWriteback::class)->afterRun($sourceId, $runId, 'ok');

    $row = $connection->fresh();
    expect($row->last_refresh_status)->toBe('ok')
        ->and($row->last_refreshed_at)->not->toBeNull()
        ->and($row->last_refresh_error)->toBeNull();
});

it('flips a pending connection to action_needed when the run carries a blocking note, and back to ok once the owner acts', function () {
    [$connection, $sourceId] = wbSourceFor(wbUser(), 'pending');

    $blocked = wbRun($sourceId, 'ok', [['code' => 'no_selection', 'message' => 'No Fresha team member or storewide menu has been chosen for this connection']]);
    app(IngestStatusWriteback::class)->afterRun($sourceId, $blocked, 'ok');

    $row = $connection->fresh();
    expect($row->last_refresh_status)->toBe('action_needed')
        ->and($row->last_refresh_error)->toContain('team member')
        ->and($row->last_refreshed_at)->toBeNull();

    // The owner picks someone → the reselected eager run comes back clean.
    $clean = wbRun($sourceId, 'ok');
    app(IngestStatusWriteback::class)->afterRun($sourceId, $clean, 'ok');

    $row = $connection->fresh();
    expect($row->last_refresh_status)->toBe('ok')
        ->and($row->last_refresh_error)->toBeNull();
});

it('maps unavailable and error outcomes, and leaves budget_skipped / deferred alone (still owed)', function () {
    $userId = wbUser();

    [$a, $sa] = wbSourceFor($userId, 'pending');
    app(IngestStatusWriteback::class)->afterRun($sa, wbRun($sa, 'unavailable'), 'unavailable');
    expect($a->fresh()->last_refresh_status)->toBe('unavailable');

    [$b, $sb] = wbSourceFor($userId, 'pending');
    app(IngestStatusWriteback::class)->afterRun($sb, wbRun($sb, 'error'), 'error');
    expect($b->fresh()->last_refresh_status)->toBe('error');

    [$c, $sc] = wbSourceFor($userId, 'pending');
    app(IngestStatusWriteback::class)->afterRun($sc, wbRun($sc, 'budget_skipped'), 'budget_skipped');
    expect($c->fresh()->last_refresh_status)->toBe('pending');
});

it('never overwrites a status the legacy lane already settled', function () {
    // Two lanes writing the same column is how a card and its panel disagree.
    // ok / error / unavailable are the legacy lane's to own; this lane only
    // moves rows that are waiting on it.
    $userId = wbUser();
    foreach (['ok', 'error', 'unavailable'] as $settled) {
        [$connection, $sourceId] = wbSourceFor($userId, $settled);
        app(IngestStatusWriteback::class)->afterRun($sourceId, wbRun($sourceId, 'ok'), 'ok');
        expect($connection->fresh()->last_refresh_status)->toBe($settled);
    }
});

it('is a no-op for a source with no connection (manual lane)', function () {
    $sourceId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $sourceId, 'user_id' => wbUser(), 'connection_id' => null,
        'source_key' => 'x', 'surface_key' => 'x', 'identifier' => 'x',
        'cost_units' => 1, 'min_interval_secs' => 1, 'max_interval_secs' => 2, 'change_rate' => 0.5,
        'next_attempt_at' => now(), 'visibility' => 1.0, 'health' => 'ok', 'consecutive_failures' => 0,
        'auto_sync' => true, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(IngestStatusWriteback::class)->afterRun($sourceId, wbRun($sourceId, 'ok'), 'ok');
    expect(true)->toBeTrue();
});

it('is wired into RunSourceJob — a run that errors flips the pending connection to error', function () {
    // source_key is unregistered so RunSourceJob throws inside handle(); the
    // finally releases the claim AND the writeback must still report 'error'
    // onto the connection rather than leaving it 'pending'.
    Exceptions::fake();
    [$connection, $sourceId] = wbSourceFor(wbUser(), 'pending');

    $job = new RunSourceJob($sourceId);
    expect(fn () => $job->handle(app(SourceScheduler::class), app(RunExecutor::class)))
        ->toThrow(InvalidArgumentException::class);

    expect($connection->fresh()->last_refresh_status)->toBe('error');
});
