<?php

use App\Ingest\Connectors\GoogleBusinessConnector;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriver;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectResult;
use App\Ingest\Runtime\RunExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupIngestTables();
    config()->set('partna.ingest.billed_effects_enabled', true);
});

/** A driver for ('api','places.details') whose run() does whatever you pass. */
function stubPlacesDriver(Closure $behaviour): BilledEffectDriver
{
    return new class($behaviour) implements BilledEffectDriver
    {
        public function __construct(private Closure $behaviour) {}

        public function supports(string $kind, string $name): bool
        {
            return $kind === 'api' && $name === 'places.details';
        }

        public function run(BilledEffectContext $ctx): BilledEffectResult
        {
            return ($this->behaviour)($ctx);
        }
    };
}

/** Seed one google_business source and run it through the REAL RunExecutor. */
function runGoogleSourceWith(BilledEffectDriver $driver): array
{
    app()->singleton(BilledEffectDriverRegistry::class, fn () => new BilledEffectDriverRegistry([$driver]));

    $sourceId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $sourceId,
        // user_id and surface_key are NOT NULL in setupIngestTables()' mirror of
        // the real DDL. user_id is doubly load-bearing here: RunExecutor threads
        // it into the driver's BilledEffectContext for per-user budget.
        'user_id' => (string) Str::uuid(),
        'surface_key' => 'google_business',
        'source_key' => 'google_business',
        'identifier' => 'ChIJabc',
        'auto_sync' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $source = (array) DB::table('ingest.sources')->where('id', $sourceId)->first();

    return app(RunExecutor::class)->execute(
        $source,
        new GoogleBusinessConnector,
        GoogleBusinessConnector::manifest(),
        'manual',
    );
}

it('folds a not-attempted refusal to budget_skipped and leaves no ledger row', function () {
    // D3's whole claim: EffectNotAttempted extends EffectRefused, so RunExecutor:87
    // already handles it and needs no change.
    $result = runGoogleSourceWith(stubPlacesDriver(
        fn () => throw new EffectNotAttempted('places daily cap reached'),
    ));

    expect($result['outcome'])->toBe('budget_skipped')
        ->and(array_unique(array_values($result['streams'])))->toBe(['budget_skipped'])
        // Released, not settled — the digest must be claimable the moment the cap resets.
        ->and(DB::table('ingest.effects')->count())->toBe(0);

    // 'budget' maps to health 'degraded', not 'unavailable' (RunExecutor::recordStreamFailure).
    expect(DB::table('ingest.streams')->pluck('health')->unique()->all())->toBe(['degraded']);
});

it('folds a no-answer to unavailable, not to error', function () {
    // D5's whole claim: returning a failed verdict rather than rethrowing keeps a
    // vendor outage out of the 'error' bucket, which reads as our own bug and
    // report()s to Nightwatch.
    $result = runGoogleSourceWith(stubPlacesDriver(
        fn () => BilledEffectResult::noAnswer('places returned 503'),
    ));

    expect($result['outcome'])->toBe('unavailable')
        ->and(array_unique(array_values($result['streams'])))->toBe(['unavailable']);

    // Settled failed and RETAINED — a request went out, so the charge is unknown.
    $effects = DB::table('ingest.effects')->get();
    expect($effects)->toHaveCount(1)
        ->and($effects[0]->status)->toBe('failed')
        ->and($effects[0]->settled_at)->not->toBeNull();

    // No anomaly: an outage is not a delete-guard or a shape violation.
    expect(DB::table('ingest.anomalies')->where('severity', 'critical')->count())->toBe(0);
});

it('folds the kill switch to budget_skipped without writing a ledger row', function () {
    config()->set('partna.ingest.billed_effects_enabled', false);

    $result = runGoogleSourceWith(stubPlacesDriver(
        fn () => BilledEffectResult::answered(['displayName' => ['text' => 'unreachable']]),
    ));

    expect($result['outcome'])->toBe('budget_skipped')
        ->and(DB::table('ingest.effects')->count())->toBe(0);
});

it('bills once for a whole run and lands records on every stream', function () {
    $calls = 0;
    $result = runGoogleSourceWith(stubPlacesDriver(function () use (&$calls) {
        $calls++;

        return BilledEffectResult::answered([
            'displayName' => ['text' => 'Maha'],
            'formattedAddress' => '21 Bond St',
            'reviews' => [['name' => 'places/abc/reviews/r1', 'rating' => 5, 'text' => ['text' => 'Great']]],
            'photos' => [['name' => 'places/abc/photos/p1']],
        ]);
    }));

    expect($result['outcome'])->toBe('ok')
        ->and($calls)->toBe(1)
        ->and($result['streams'])->toBe(['profile' => 'ok', 'reviews' => 'ok', 'media' => 'ok'])
        ->and(DB::table('ingest.effects')->where('status', 'ok')->count())->toBe(1);

    expect(DB::table('ingest.record_state')->whereNull('tombstoned_at')->count())->toBe(3);
});
