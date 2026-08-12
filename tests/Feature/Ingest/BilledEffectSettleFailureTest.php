<?php

use App\Ingest\Runtime\EffectLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// #MONEY-1. The vendor call SUCCEEDS and the settle UPDATE then fails. Before
// this fix the catch-all assumed "we are in here, so the call failed": it
// stamped the row 'failed', threw the paid result away, and locked the digest
// out of verdictFor() for the whole seven-day freshness window.

beforeEach(function () {
    setupIngestTables();

    // A real DB failure on the settle write, not a mock. The schema qualifier
    // goes on the TRIGGER name, not the table — same SQLite quirk as the index
    // stand-ins in tests/Pest.php.
    DB::connection('pgsql')->statement(
        "CREATE TRIGGER ingest.tg_effects_settle_boom BEFORE UPDATE ON effects
         WHEN NEW.digest = 'settle-boom'
         BEGIN SELECT RAISE(ABORT, 'settle write failed'); END"
    );
});

afterEach(function () {
    DB::connection('pgsql')->statement('DROP TRIGGER IF EXISTS ingest.tg_effects_settle_boom');
});

it('returns the paid-for result even when the settle write fails', function () {
    $ledger = new EffectLedger;

    $outcome = $ledger->once(
        digest: 'settle-boom',
        kind: 'api',
        effect: fn () => ['place' => 'answered', 'rating' => 4.5],
        costTag: 'places.details',
    );

    // The whole point: we paid, we got an answer, the caller receives it.
    expect($outcome['status'])->toBe('ok')
        ->and($outcome['result'])->toBe(['place' => 'answered', 'rating' => 4.5])
        ->and($outcome['cached'])->toBeFalse();
});

it('never stamps a successful paid call as failed', function () {
    $ledger = new EffectLedger;

    $ledger->once(
        digest: 'settle-boom',
        kind: 'api',
        effect: fn () => ['place' => 'answered'],
        costTag: 'places.details',
    );

    $row = DB::table('ingest.effects')->where('digest', 'settle-boom')->first();

    // 'failed' would be a lie about a call that succeeded, and it would make
    // verdictFor() serve that lie for the rest of the freshness window.
    expect($row->status)->not->toBe('failed')
        // Left CLAIMED and unsettled: the honest "we paid, the books do not
        // know it" state, and the one markAbandoned() already owns.
        ->and($row->status)->toBe('claimed')
        ->and($row->settled_at)->toBeNull();
});

it('logs the unrecorded charge loudly, without touching the database', function () {
    // We are already in a path entered BECAUSE a DB write failed. The alarm
    // must not itself be a DB write — see settleOk()'s docblock.
    Log::spy();
    $ledger = new EffectLedger;

    $ledger->once(
        digest: 'settle-boom',
        kind: 'api',
        effect: fn () => ['place' => 'answered'],
        costTag: 'places.details',
    );

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context = []) => $message === 'ingest.effect.settle_unrecorded'
            && ($context['digest'] ?? null) === 'settle-boom'
            && ($context['cost_tag'] ?? null) === 'places.details')
        ->once();

    expect(DB::table('ingest.anomalies')->count())->toBe(0);
});

it('still settles and rethrows when the EFFECT itself fails', function () {
    // The regression gate for the narrowing: a genuine vendor failure must
    // keep its old behaviour exactly — settled 'failed', exception rethrown.
    $ledger = new EffectLedger;

    expect(fn () => $ledger->once(
        digest: 'effect-really-failed',
        kind: 'api',
        effect: fn () => throw new RuntimeException('vendor 500'),
        costTag: 'places.details',
    ))->toThrow(RuntimeException::class, 'vendor 500');

    $row = DB::table('ingest.effects')->where('digest', 'effect-really-failed')->first();
    expect($row->status)->toBe('failed')
        ->and($row->settled_at)->not->toBeNull()
        ->and(json_decode((string) $row->meta, true)['error'])->toBe('RuntimeException');
});
