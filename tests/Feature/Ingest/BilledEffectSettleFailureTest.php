<?php

use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Runtime\EffectLedger;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriver;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectResult;
use App\Ingest\Runtime\HttpIo;
use App\Services\Http\SafeUrlFetcher;
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

/**
 * A driver that answers successfully. Deliberately NOT named recordingDriver()
 * / ioWith() like BilledEffectDispatchTest's helpers — Pest shares one global
 * function namespace, so a full-suite run loading both files would fatal on a
 * redeclare.
 */
function settleFailDriver(): BilledEffectDriver
{
    return new class implements BilledEffectDriver
    {
        public function supports(string $kind, string $name): bool
        {
            return $kind === 'api' && $name === 'places.details';
        }

        public function run(BilledEffectContext $ctx): BilledEffectResult
        {
            return BilledEffectResult::answered(['place' => 'answered']);
        }
    };
}

it('hands the connector its paid data even when the ledger cannot record it', function () {
    // Same failure, one layer up. HttpIo is the only production Io, so this is
    // the seam a real connector sees: `data` must carry the answer, not null.
    config()->set('partna.ingest.billed_effects_enabled', true);
    config()->set('partna.ingest.effect_freshness_seconds', 604800);

    // Fire on the SETTLE write specifically — NEW.status = 'ok' — so the claim
    // insert still succeeds and we reproduce "paid, then bookkeeping failed"
    // rather than "could not claim". No digest arithmetic needed.
    DB::connection('pgsql')->statement(
        "CREATE TRIGGER ingest.tg_effects_ok_boom BEFORE UPDATE ON effects
         WHEN NEW.status = 'ok'
         BEGIN SELECT RAISE(ABORT, 'settle write failed'); END"
    );

    $io = new HttpIo(
        manifest: new Manifest(source: SourceKey::of('settle_fail_test'), identifierKind: 'test', hosts: [], streams: [], cost: CostClass::Metered),
        fetcher: app(SafeUrlFetcher::class),
        ledger: new EffectLedger,
        drivers: new BilledEffectDriverRegistry([settleFailDriver()]),
        runId: 'run-1',
        sourceId: 'source-1',
        userId: 'user-1',
    );

    try {
        $outcome = $io->effect('api', 'places.details', ['place_id' => 'ChIJtest']);
    } finally {
        DB::connection('pgsql')->statement('DROP TRIGGER IF EXISTS ingest.tg_effects_ok_boom');
    }

    expect($outcome['status'])->toBe('ok')
        ->and($outcome['data'])->toBe(['place' => 'answered'])
        ->and($outcome['cached'])->toBeFalse();
});
