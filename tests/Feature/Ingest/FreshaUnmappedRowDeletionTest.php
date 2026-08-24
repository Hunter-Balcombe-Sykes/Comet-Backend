<?php

// The data-loss regression this fixes (SEM-1, P1): the services stream is
// deletesOnExhaustive, so an unconditional Coverage::exhaustive() claim on
// every run licensed Lander::foldAbsence to tombstone any live key the batch
// omitted — including a row omitted because THIS run's mapper could not read
// it, not because the salon deleted it. Three of one salon's rows, 12% of
// its menu, vanished with no signal (2026-08-18). This wires the REAL
// FreshaConnector to the REAL Lander (no mocks, no RunExecutor) and proves
// the fix across multiple runs — a single bad run tombstones nothing
// regardless, since tombstoning needs 3 consecutive dominated absences; the
// loss only shows up once you carry the mapper gap across those runs.

use App\Ingest\Connectors\FreshaConnector;
use App\Ingest\Landing\Lander;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => setupIngestTables());

// Naming hazard: Pest loads every *Test.php file into one process, so
// top-level `function` declarations are GLOBAL across files.
// FreshaConnectorTest.php already declares freshaIo()/freshaPull()/
// freshaBookingFlowBody()/normalItem(), and LanderTest.php already declares
// seedIngestStream()/ingestRecordState() — redeclaring any of those is a
// fatal error, and depending on cross-file load order for the ones we DON'T
// redeclare is fragile. This file's helpers are self-contained and uniquely
// named instead.

/** A fixed-response Io — this connector only ever needs one POST answered per run. */
function freshaFoldIo(array $response): Io
{
    return new class($response) implements Io
    {
        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            return $this->response;
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

/** @param  list<array<string,mixed>>  $categories */
function freshaFoldBody(array $categories): array
{
    return [
        'status' => 200,
        'body' => json_encode(['data' => ['bookingFlowInitialize' => ['screenServices' => ['categories' => $categories]]]]),
        'headers' => [],
    ];
}

/** A cleanly mappable row — its catalog id round-trips through mapServiceItem(). */
function freshaFoldItem(string $catalogId): array
{
    return [
        'name' => 'Service '.$catalogId,
        'caption' => '30min',
        'price' => ['formatted' => 'from $40'],
        'primaryAction' => ['id' => '{"catalogId":"'.$catalogId.'"}'],
    ];
}

/** A row the mapper cannot read at all — no catalogId anywhere in either action id. */
function freshaFoldMangledItem(): array
{
    return [
        'name' => 'Mystery',
        'primaryAction' => ['id' => '[{"type":"whatever"}]'],
    ];
}

function freshaFoldState(string $streamId, string $key): ?object
{
    return DB::table('ingest.record_state')->where('stream_id', $streamId)->where('key', $key)->first();
}

/**
 * Runs the real connector against one canned response, lands the result
 * through the real Lander, and returns the land() summary — the shared
 * harness every scenario below drives.
 *
 * @return array{seen: int, changed: int, tombstoned: int, guard_tripped: bool, failed: int}
 */
function freshaFoldRun(string $streamId, string $runId, array $categories): array
{
    $io = freshaFoldIo(freshaFoldBody($categories));
    $pull = new Pull(
        identifier: 'invented-salon',
        stream: FreshaConnector::manifest()->stream('services'),
        config: ['selection_ref' => 'storewide'],
    );

    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    return (new Lander)->land($streamId, $runId, FreshaConnector::manifest()->stream('services'), $records, $covered);
}

/** 10 cleanly mappable services: s:1 .. s:10. */
function freshaFoldTenServices(): array
{
    return array_map(fn ($i) => freshaFoldItem("s:{$i}"), range(1, 10));
}

it('accrues no absence against a menu whose rows it could not all read', function () {
    freshaFoldRun('s1', 'r1', [['id' => '1', 'name' => 'Cuts', 'items' => freshaFoldTenServices()]]);

    // s:10's row is replaced by an unreadable one — same menu, one bad row.
    $items = [...array_map(fn ($i) => freshaFoldItem("s:{$i}"), range(1, 9)), freshaFoldMangledItem()];
    $result = freshaFoldRun('s1', 'r2', [['id' => '1', 'name' => 'Cuts', 'items' => $items]]);

    expect($result['tombstoned'])->toBe(0)
        ->and($result['guard_tripped'])->toBeFalse();

    $state = freshaFoldState('s1', 's:10');
    expect((int) $state->absent_runs)->toBe(0)
        ->and($state->absent_since)->toBeNull();
});

it('still deletes nothing after three consecutive runs with the same unreadable row', function () {
    // The data-loss proof: the mapper gap PERSISTS for three runs in a row —
    // exactly the count that would tombstone under exhaustive coverage — and
    // still nothing is deleted, because every one of those runs claims only
    // unknown coverage.
    freshaFoldRun('s1', 'r1', [['id' => '1', 'name' => 'Cuts', 'items' => freshaFoldTenServices()]]);

    $itemsWithGap = [...array_map(fn ($i) => freshaFoldItem("s:{$i}"), range(1, 9)), freshaFoldMangledItem()];
    $results = [];
    foreach (['r2', 'r3', 'r4'] as $runId) {
        $results[] = freshaFoldRun('s1', $runId, [['id' => '1', 'name' => 'Cuts', 'items' => $itemsWithGap]]);
    }

    foreach ($results as $result) {
        expect($result['tombstoned'])->toBe(0)
            // 1 surprise / 10 live keys = 10%, below the 40% delete-guard
            // threshold, and count 1 < 5 — this proves the FIX is doing the
            // work, not the delete-guard masking it.
            ->and($result['guard_tripped'])->toBeFalse();
    }

    $state = freshaFoldState('s1', 's:10');
    expect($state->tombstoned_at)->toBeNull();

    $tombstonedTotal = DB::table('ingest.record_state')->where('stream_id', 's1')->whereNotNull('tombstoned_at')->count();
    expect($tombstonedTotal)->toBe(0);

    $anomaly = DB::table('ingest.anomalies')->where('stream_id', 's1')->first();
    expect($anomaly)->toBeNull();
});

it('still tombstones a service the salon really removed, once the whole menu parses', function () {
    // The anti-over-correction guard: without this, a naive "never claim
    // exhaustive" fix would pass the two tests above and silently kill all
    // legitimate deletion too.
    freshaFoldRun('s1', 'r1', [['id' => '1', 'name' => 'Cuts', 'items' => freshaFoldTenServices()]]);

    // s:10 genuinely removed from the menu — the other nine parse cleanly,
    // nothing is unmappable, so the claim stays exhaustive.
    $nineServices = array_map(fn ($i) => freshaFoldItem("s:{$i}"), range(1, 9));
    $results = [];
    foreach (['r2', 'r3', 'r4'] as $runId) {
        $results[] = freshaFoldRun('s1', $runId, [['id' => '1', 'name' => 'Cuts', 'items' => $nineServices]]);
    }

    $state = freshaFoldState('s1', 's:10');
    expect($state->tombstoned_at)->not->toBeNull();

    $tombstonedTotal = DB::table('ingest.record_state')->where('stream_id', 's1')->whereNotNull('tombstoned_at')->count();
    expect($tombstonedTotal)->toBe(1);
});
