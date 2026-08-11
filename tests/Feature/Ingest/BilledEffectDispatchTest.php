<?php

use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Runtime\EffectLedger;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriver;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectResult;
use App\Ingest\Runtime\HttpIo;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupIngestTables();
    config()->set('partna.ingest.billed_effects_enabled', true);
    config()->set('partna.ingest.effect_freshness_seconds', 604800);
});

/** A driver that records what it was handed and returns whatever it was told to. */
function recordingDriver(string $kind, string $name, Closure $behaviour): BilledEffectDriver
{
    return new class($kind, $name, $behaviour) implements BilledEffectDriver
    {
        public ?BilledEffectContext $seen = null;

        public function __construct(
            private string $kind,
            private string $name,
            private Closure $behaviour,
        ) {}

        public function supports(string $kind, string $name): bool
        {
            return $kind === $this->kind && $name === $this->name;
        }

        public function run(BilledEffectContext $ctx): BilledEffectResult
        {
            $this->seen = $ctx;

            return ($this->behaviour)($ctx);
        }
    };
}

function ioWith(BilledEffectDriverRegistry $registry, ?string $userId = 'user-1'): HttpIo
{
    return new HttpIo(
        manifest: new Manifest(source: SourceKey::of('dispatch_test'), identifierKind: 'test', hosts: [], streams: [], cost: CostClass::Metered),
        fetcher: app(SafeUrlFetcher::class),
        ledger: new EffectLedger,
        drivers: $registry,
        runId: 'run-1',
        sourceId: 'source-1',
        userId: $userId,
    );
}

it('routes an effect to the driver that claims its (kind, name) and hands it the run context', function () {
    $driver = recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered(['displayName' => ['text' => 'Maha']]));
    $io = ioWith(new BilledEffectDriverRegistry([$driver]));

    $outcome = $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']);

    expect($outcome['status'])->toBe('ok')
        ->and($outcome['data'])->toBe(['displayName' => ['text' => 'Maha']])
        ->and($driver->seen->input)->toBe(['place_id' => 'ChIJabc'])
        ->and($driver->seen->runId)->toBe('run-1')
        ->and($driver->seen->sourceId)->toBe('source-1')
        ->and($driver->seen->userId)->toBe('user-1');
});

it('still throws for a (kind, name) no driver claims', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered([])),
    ]));

    expect(fn () => $io->effect('actor', 'menu', ['url' => 'https://example.test']))
        ->toThrow(RuntimeException::class, "No billed-effect driver is wired for kind 'actor'");
});

it('refuses every billed effect when the kill switch is off, without touching the ledger', function () {
    config()->set('partna.ingest.billed_effects_enabled', false);

    $driver = recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered(['unreachable']));
    $io = ioWith(new BilledEffectDriverRegistry([$driver]));

    expect(fn () => $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']))
        ->toThrow(EffectRefused::class);

    expect($driver->seen)->toBeNull()
        ->and(DB::table('ingest.effects')->count())->toBe(0);
});

it('leaves no ledger row when a driver refuses on budget', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => throw new EffectNotAttempted('places cap reached')),
    ]));

    expect(fn () => $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']))
        ->toThrow(EffectNotAttempted::class);

    expect(DB::table('ingest.effects')->count())->toBe(0);
});

it('turns a driver no-answer into a failed verdict rather than an ok-with-null', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => BilledEffectResult::noAnswer('places returned 503')),
    ]));

    $outcome = $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']);

    expect($outcome)->toBe(['status' => 'failed', 'cached' => false, 'data' => null]);

    $row = DB::table('ingest.effects')->where('kind', 'api')->first();
    expect($row->status)->toBe('failed')
        ->and(json_decode((string) $row->meta, true)['message'])->toBe('places returned 503');
});

it('settles an answered-with-null as ok so a dead identifier is not re-billed', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered(null)),
    ]));

    expect($io->effect('api', 'places.details', ['place_id' => 'ChIJgone']))
        ->toBe(['status' => 'ok', 'cached' => false, 'data' => null]);

    expect(DB::table('ingest.effects')->where('kind', 'api')->value('status'))->toBe('ok');
});

it('replays the second stream of a run from the ledger instead of billing twice', function () {
    // InstagramConnector calls effect() once per stream with identical input, so
    // the profile and media streams of one run share a digest by design.
    $calls = 0;
    $driver = recordingDriver('actor', 'instagram', function () use (&$calls) {
        $calls++;

        return BilledEffectResult::answered([['username' => 'maha']]);
    });
    $io = ioWith(new BilledEffectDriverRegistry([$driver]));

    $first = $io->effect('actor', 'instagram', ['username' => 'maha', 'include_posts' => true]);
    $second = $io->effect('actor', 'instagram', ['username' => 'maha', 'include_posts' => true]);

    expect($calls)->toBe(1)
        ->and($first['cached'])->toBeFalse()
        ->and($second['cached'])->toBeTrue()
        ->and($second['data'])->toBe([['username' => 'maha']]);
});
