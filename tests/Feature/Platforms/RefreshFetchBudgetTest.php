<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\ShopCatalog;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

// Task B: PlatformRefresher::refresh() is the ONE chokepoint every refresh (cron,
// manual button, ShopController, observer, ShopBrandConnectJob) flows through. Its
// whole body now runs inside FetchBudget::ensureOpen(refresh_budget_seconds, ...) so
// a pathological scrape can never ride RefreshConnectionJob's 120s $timeout to a
// SIGKILL — and its own deadline expiring mid-refresh is a QUIET non-event:
//   Trap A — a budget exhaustion must NOT arm the circuit breaker, page Nightwatch,
//            or notify the user, unlike every other failure bucket.
//   Trap B — FetchBudget::exhausted() reads true only while the budget is still
//            OPEN, so the SafeUrlException catch must live INSIDE the ensureOpen
//            closure; a genuine (budget-not-exhausted) SafeUrlException must still
//            be rethrown so it keeps today's loud 'error' terminal state.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

// ── 1. Mechanism proof ───────────────────────────────────────────────────────
// Proves PlatformRefresher's ensureOpen() and FreshaScraper's remaining() read the
// SAME scoped FetchBudget instance across the real refresh path (registry ->
// ScheduledRefresh -> FreshaFetch -> app(FreshaScraper::class)) — not two budgets
// that happen to share a class.

it('clamps the deep employee-menu fetch to the refresh budget, proving PlatformRefresher and the scraper share one scoped FetchBudget', function () {
    config(['partna.http_fetch.refresh_budget_seconds' => 5]);

    $user = createTenant('rfb1');
    $selection = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'employee',
        'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
        'services' => [],
        'hiddenServiceIds' => [],
    ];
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => $selection['url'], 'selection' => $selection],
    ]);

    $seen = null;
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen = $options['timeout'] ?? null;

        return Http::response(['data' => ['bookingFlowInitialize' => ['screenServices' => ['categories' => [
            [
                'name' => 'Cuts',
                'items' => [
                    ['name' => 'Fade', 'primaryAction' => ['id' => '{"catalogId":"s:1"}']],
                ],
            ],
        ]]]]], 200);
    });

    app(PlatformRefresher::class)->refresh($conn->refresh());

    // Pre-fix this is 12: no budget is opened anywhere on the refresh path, so
    // FreshaScraper::remaining() reads null and the flat GraphQL ceiling applies.
    // 5 (the configured refresh budget) proves the deep scraper is reading the
    // SAME budget PlatformRefresher just opened.
    expect($seen)->toBe(5);
});

// ── 2. Budget exhaustion is quiet (Trap A) ──────────────────────────────────

it('makes a genuinely exhausted refresh budget a quiet non-event — no breaker arm, no notify, no report', function () {
    config(['partna.http_fetch.refresh_budget_seconds' => 0]);
    Exceptions::fake();
    $this->mock(PlatformHealthNotifier::class, function ($mock) {
        $mock->shouldNotReceive('connectionRefreshFailing');
    });
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    $user = createTenant('rfb2');
    $selection = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'storewide',
        'employee' => null,
        'services' => [freshaService('s:1', 'Cut')],
        'hiddenServiceIds' => [],
    ];
    $payload = ['url' => $selection['url'], 'selection' => $selection];
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => $payload, 'consecutive_failures' => 2,
    ]);

    // Must return normally — no throw — even though FreshaScraper::fetchLocation()
    // (via the exhausted SafeUrlFetcher double) raises SafeUrlException underneath.
    $result = app(PlatformRefresher::class)->refresh($conn->refresh());

    expect($result)->toBeInstanceOf(IntegrationConnection::class);

    $fresh = $conn->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable')
        ->and($fresh->last_refresh_error)->toBe('refresh_budget_exhausted')
        ->and($fresh->payload)->toBe($payload) // byte-preserved
        ->and($fresh->consecutive_failures)->toBe(2); // unchanged — breaker NOT armed

    Exceptions::assertNothingReported();
});

// ── 3. A genuine failure still errors (Trap B — did not over-quiet) ────────

it('rethrows a genuine SafeUrlException when the budget is NOT exhausted', function () {
    config(['partna.http_fetch.refresh_budget_seconds' => 90]); // comfortably large — the default
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    $user = createTenant('rfb3');
    $selection = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'storewide',
        'employee' => null,
        'services' => [freshaService('s:1', 'Cut')],
        'hiddenServiceIds' => [],
    ];
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => $selection['url'], 'selection' => $selection],
    ]);

    // The budget has 90s of headroom, so exhausted() reads false at catch time —
    // a real SSRF block / connection failure, not our own deadline — must be
    // rethrown so RefreshConnectionJob::failed() (unchanged) still gets it and
    // keeps today's loud 'error' + consecutive_failures++ terminal state.
    expect(fn () => app(PlatformRefresher::class)->refresh($conn->refresh()))
        ->toThrow(SafeUrlException::class);

    expect($conn->fresh()->last_refresh_error)->not->toBe('refresh_budget_exhausted');
});

// ── 4. A fast/healthy refresh is unaffected ─────────────────────────────────

it('a healthy refresh well inside the budget stays byte-identical', function () {
    config(['partna.http_fetch.refresh_budget_seconds' => 90]);

    $user = createTenant('rfb4');
    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('fetchLocation')->once()->andReturn(['name' => 'Acme Cuts']);
        $m->shouldReceive('extractServices')->once()->andReturn([
            freshaService('s:1', 'Cut', '$55'),
        ]);
        $m->shouldReceive('extractStoreName')->once()->andReturn('Acme Cuts');
    });

    $selection = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'storewide',
        'employee' => null,
        'services' => [freshaService('s:1', 'Cut')],
        'hiddenServiceIds' => [],
    ];
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => $selection['url'], 'selection' => $selection],
    ]);

    app(PlatformRefresher::class)->refresh($conn->refresh());

    $fresh = $conn->fresh();
    expect($fresh->last_refresh_status)->toBe('ok')
        ->and($fresh->consecutive_failures)->toBe(0)
        ->and($fresh->payload['selection']['services'][0]['price'])->toBe('$55')
        ->and($fresh->payload['selection']['storeName'])->toBe('Acme Cuts');
});

// ── 5. A shop refresh still behaves correctly under the new wrap ───────────
// Reuses ShopSyncFailureObservabilityTest's shopSyncUser/shopSyncBrand fixtures —
// no need to rebuild the shop scaffolding for what is otherwise the same
// end-to-end PlatformRefresher::refresh() path those tests already exercise.

it('a shop platform refresh still returns its normal result under the ensureOpen() wrap', function () {
    config(['partna.http_fetch.refresh_budget_seconds' => 90]);

    $user = shopSyncUser('rfb5');
    $brand = shopSyncBrand($user, 'b1');

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldReceive('syncLatest')->once()->andReturn(3);
    $this->app->instance(ShopCatalog::class, $catalog);

    $cacheRefresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $cacheRefresher->shouldReceive('refresh')->once();
    $this->app->instance(IntegrationConnectionCacheRefresher::class, $cacheRefresher);

    app(PlatformRefresher::class)->refresh($brand->connection->fresh());

    expect($brand->connection->fresh()->last_refresh_status)->toBe('ok');
});
