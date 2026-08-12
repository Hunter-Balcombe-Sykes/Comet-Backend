<?php

use App\Ingest\Connectors\GoogleBusinessConnector;
use App\Ingest\Message\Record;
use App\Ingest\Runtime\EffectLedger;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\PlacesDetailsDriver;
use App\Ingest\Runtime\HttpIo;
use App\Ingest\Runtime\Pull;
use App\Services\Cache\PlacesBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// NOTE: no `use App\Ingest\Runtime\EffectRefused` — EffectNotAttempted extends it,
// so asserting on the parent would pass for either and prove nothing about which
// claim-handling path ran. Always assert the exact subclass here.

beforeEach(function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    config()->set('partna.limits.places.details_max_attempts', 1);
    config()->set('partna.limits.places.global_daily_cap', 100);
    config()->set('partna.limits.places.skus.details', 100);
    config()->set('partna.limits.places.per_user_daily_cap', 100);
});

function placesCtx(array $input = ['place_id' => 'ChIJabc'], ?string $userId = 'user-1'): BilledEffectContext
{
    return new BilledEffectContext('api', 'places.details', $input, 'run-1', 'source-1', $userId);
}

it('claims only its own (kind, name)', function () {
    $driver = app(PlacesDetailsDriver::class);

    expect($driver->supports('api', 'places.details'))->toBeTrue()
        ->and($driver->supports('actor', 'places.details'))->toBeFalse()
        ->and($driver->supports('api', 'places.photos'))->toBeFalse();
});

it('returns the raw Places response so the connector can read the vendor shape', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'photos' => [['name' => 'places/abc/photos/xyz']],
    ], 200)]);

    $result = app(PlacesDetailsDriver::class)->run(placesCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data['displayName']['text'])->toBe('Maha')
        ->and($result->data['photos'][0]['name'])->toBe('places/abc/photos/xyz');
});

it('refuses on budget before any request, so the ledger claim can be released', function () {
    config()->set('partna.limits.places.per_user_daily_cap', 0);
    Http::fake();

    expect(fn () => app(PlacesDetailsDriver::class)->run(placesCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('treats a 404 as an answer with no data, not an outage', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

    $result = app(PlacesDetailsDriver::class)->run(placesCtx(['place_id' => 'ChIJgone']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toBeNull();
});

// ⚠️ ONE CAUSE PER TEST. Http::fake() MERGES stubs and the FIRST match wins, so a
// combined version would exercise one status four times while reading as if it
// covered four. Spec §5.4 requires each of the four null causes proven separately.
it('reports a 503 as NoAnswer', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 503)]);

    expect(app(PlacesDetailsDriver::class)->run(placesCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reports a 429 as NoAnswer', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 429)]);

    expect(app(PlacesDetailsDriver::class)->run(placesCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reports a transport failure as NoAnswer', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(app(PlacesDetailsDriver::class)->run(placesCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('refuses without attempting anything when the server key is unset', function () {
    // NOT NoAnswer: nothing was sent, so the ledger claim must be released and the
    // digest stay retryable — otherwise one missing env var locks every place for
    // the freshness window, and stays locked after the var is set.
    config()->set('services.google_maps.server_api_key', '');
    Http::fake();

    expect(fn () => app(PlacesDetailsDriver::class)->run(placesCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('refuses rather than spending when the effect carries no place id or no user', function () {
    Http::fake();

    expect(app(PlacesDetailsDriver::class)->run(placesCtx([]))->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    expect(app(PlacesDetailsDriver::class)->run(placesCtx(userId: null))->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    Http::assertNothingSent();
});

it('treats a mid-loop budget denial as NoAnswer, never as a clean refusal', function () {
    // The second attempt only happens after a transport failure, which means a
    // request already reached Google. That is not "nothing was sent", so it must
    // NOT surface as EffectNotAttempted — the ledger would release the claim.
    //
    // It must also not ESCAPE: an uncaught PlacesBudgetExhaustedException reaches
    // RunExecutor's catch-all, marking the stream 'error' and paging on-call for a
    // spend ceiling. NoAnswer settles the row failed (claim retained, which is what
    // "a request already went out" requires) and folds to Unavailable.
    config()->set('partna.limits.places.details_max_attempts', 2);
    config()->set('partna.limits.places.per_user_daily_cap', 1);
    config()->set('partna.limits.places.details_retry_delay_microseconds', 0);
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $result = app(PlacesDetailsDriver::class)->run(placesCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($result->reason)->toContain('budget');
});

it('lets GoogleBusinessConnector produce profile, review and media records', function () {
    // The whole point of slice 0: this connector could not complete a run at all
    // before, because HttpIo::runBilledEffect() threw unconditionally.
    config()->set('partna.ingest.billed_effects_enabled', true);
    setupIngestTables();

    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'formattedAddress' => '21 Bond St, Melbourne',
        'nationalPhoneNumber' => '03 9000 0000',
        'websiteUri' => 'https://maha.test',
        'reviews' => [[
            'name' => 'places/abc/reviews/r1',
            'rating' => 5,
            'text' => ['text' => 'Excellent'],
            'authorAttribution' => ['displayName' => 'Sam', 'uri' => 'https://maps.test/sam'],
            'publishTime' => '2026-08-01T00:00:00Z',
        ]],
        'photos' => [['name' => 'places/abc/photos/p1', 'widthPx' => 1200, 'heightPx' => 800]],
    ], 200)]);

    $io = new HttpIo(
        manifest: GoogleBusinessConnector::manifest(),
        fetcher: app(SafeUrlFetcher::class),
        ledger: new EffectLedger,
        drivers: new BilledEffectDriverRegistry([app(PlacesDetailsDriver::class)]),
        runId: 'run-1',
        sourceId: 'source-1',
        userId: 'user-1',
    );

    $manifest = GoogleBusinessConnector::manifest();
    $connector = new GoogleBusinessConnector;

    $collect = function (string $stream) use ($connector, $io, $manifest): array {
        $pull = new Pull(
            identifier: 'ChIJabc',
            stream: $manifest->streams[$stream],
            cursor: [],
            config: ['scope' => 'all', 'scope_n' => null],
            isClaimed: true,
        );

        $records = [];
        foreach ($connector->pull($pull, $io) as $message) {
            if ($message instanceof Record) {
                $records[] = $message;
            }
        }

        return $records;
    };

    $profile = $collect('profile');
    expect($profile)->toHaveCount(1)
        ->and($profile[0]->doc['display_name'])->toBe('Maha')
        ->and($profile[0]->doc['website'])->toBe('https://maha.test');

    // The reviewer-PII keys the manifest's when_unclaimed redaction is declared
    // over must be PRESENT here — the Lander strips them, not the connector, and a
    // mapped payload would have made that redaction vacuous.
    $reviews = $collect('reviews');
    expect($reviews)->toHaveCount(1)
        ->and($reviews[0]->key)->toBe('places/abc/reviews/r1')
        ->and($reviews[0]->doc['author'])->toBe('Sam')
        ->and($reviews[0]->doc['text'])->toBe('Excellent');

    $media = $collect('media');
    expect($media)->toHaveCount(1)
        ->and($media[0]->doc['ref'])->toBe('places/abc/photos/p1')
        ->and($media[0]->doc['width_px'])->toBe(1200);

    // One billed DETAILS request for all three streams — the other two replayed
    // the digest. Slice 1b added photo-media calls to the same effect, so a bare
    // assertSentCount() no longer expresses this; count by endpoint instead,
    // which is what the assertion always meant. Splitting media onto its own
    // effect kind would show up here as a second Details call.
    $details = 0;
    $media = 0;
    foreach (Http::recorded() as [$request, $response]) {
        str_contains($request->url(), '/media') ? $media++ : $details++;
    }

    expect($details)->toBe(1)
        ->and($media)->toBe(1);
});

it('precheck allows the effect through when the budget cache is unavailable', function () {
    // #MONEY-2 follow-up. remaining() is the ONE PlacesBudget method with no
    // try/catch — claim() degrades a cache outage to Unavailable, remaining()
    // calls Cache::get() bare, and those reads throw once the Redis request
    // breaker opens. EffectLedger::once() invokes precheck() OUTSIDE every try
    // it has, so an escaping exception would reach RunExecutor's catch-all and
    // mark the stream 'error' — paging on-call for a Valkey blip that used to
    // fold quietly to 'budget_skipped'.
    //
    // Allowing is safe only because run()'s claim() is the real gate and still
    // fails closed. Failing closed HERE would silently skip billed work.
    $exploding = new class extends PlacesBudget
    {
        public function remaining(string $sku, ?string $userId = null): int
        {
            throw new RuntimeException('Redis unavailable (breaker open)');
        }
    };

    $driver = new PlacesDetailsDriver(app(GoogleBusinessService::class), $exploding);

    $context = new BilledEffectContext(
        kind: 'api',
        name: 'places.details',
        input: ['place_id' => 'ChIJtest'],
        runId: 'run-1',
        sourceId: 'source-1',
        userId: 'user-1',
    );

    // No exception of ANY kind — not EffectNotAttempted either. The effect
    // proceeds to run(), which gates it properly.
    $driver->precheck($context);

    expect(true)->toBeTrue();
});

it('precheck still refuses a genuinely exhausted budget', function () {
    // The guard above must not swallow the case the precheck exists for.
    $exhausted = new class extends PlacesBudget
    {
        public function remaining(string $sku, ?string $userId = null): int
        {
            return 0;
        }
    };

    $driver = new PlacesDetailsDriver(app(GoogleBusinessService::class), $exhausted);

    expect(fn () => $driver->precheck(new BilledEffectContext(
        kind: 'api',
        name: 'places.details',
        input: ['place_id' => 'ChIJtest'],
        runId: 'run-1',
        sourceId: 'source-1',
        userId: 'user-1',
    )))->toThrow(EffectNotAttempted::class);
});
