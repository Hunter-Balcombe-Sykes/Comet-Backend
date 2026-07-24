<?php

// OBS-1: FreshaScraper::fetchEmployeeServices() previously degraded to a silent
// Log::warning + null on all 3 failure branches — invisible to Nightwatch, while
// FreshaFetch quietly falls back to the whole-location menu and the circuit
// breaker records 'ok'. This pins the fix: each branch now ALSO reports a
// FreshaEmployeeMenuUnavailableException, while the null-return + fallback
// contract stays byte-identical (purely additive).

use App\Exceptions\Platforms\FreshaEmployeeMenuUnavailableException;
use App\Services\Http\FetchBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\FreshaScraper;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

function freshaEmployeeScraper(): FreshaScraper
{
    return new FreshaScraper(Mockery::mock(SafeUrlFetcher::class), app(FetchBudget::class));
}

it('reports a FreshaEmployeeMenuUnavailableException when the GraphQL call throws', function () {
    Exceptions::fake();
    Http::fake(fn () => throw new Exception('connection reset'));

    $result = freshaEmployeeScraper()->fetchEmployeeServices('acme', 'e1');

    expect($result)->toBeNull(); // fallback contract preserved
    Exceptions::assertReported(
        fn (FreshaEmployeeMenuUnavailableException $e) => $e->slug === 'acme'
            && $e->employeeId === 'e1'
            && $e->reason === 'exception'
            && $e->getPrevious() !== null
    );
});

it('reports a FreshaEmployeeMenuUnavailableException on a non-2xx GraphQL response', function () {
    Exceptions::fake();
    Http::fake(['www.fresha.com/graphql' => Http::response(['error' => 'server error'], 500)]);

    $result = freshaEmployeeScraper()->fetchEmployeeServices('acme', 'e1');

    expect($result)->toBeNull();
    Exceptions::assertReported(
        fn (FreshaEmployeeMenuUnavailableException $e) => $e->slug === 'acme'
            && $e->employeeId === 'e1'
            && $e->reason === 'http_error'
            && $e->status === 500
    );
});

it('reports a FreshaEmployeeMenuUnavailableException when a 2xx response is missing categories', function () {
    Exceptions::fake();
    // The classic hash/version-rotation symptom: 200 OK, but the expected
    // categories key is gone from the GraphQL response shape.
    Http::fake(['www.fresha.com/graphql' => Http::response(['data' => ['bookingFlowInitialize' => ['screenServices' => []]]], 200)]);

    $result = freshaEmployeeScraper()->fetchEmployeeServices('acme', 'e1');

    expect($result)->toBeNull();
    Exceptions::assertReported(
        fn (FreshaEmployeeMenuUnavailableException $e) => $e->slug === 'acme'
            && $e->employeeId === 'e1'
            && $e->reason === 'no_categories'
            && $e->status === null
    );
});

it('does not report anything on a healthy employee-menu fetch', function () {
    Exceptions::fake();
    Http::fake(['www.fresha.com/graphql' => Http::response([
        'data' => ['bookingFlowInitialize' => ['screenServices' => ['categories' => [
            [
                'name' => 'Cuts',
                'items' => [
                    ['name' => 'Fade', 'primaryAction' => ['id' => '{"catalogId":"s:1"}']],
                ],
            ],
        ]]]],
    ], 200)]);

    $result = freshaEmployeeScraper()->fetchEmployeeServices('acme', 'e1');

    expect($result)->toHaveCount(1)
        ->and($result[0]['serviceId'])->toBe('s:1');
    Exceptions::assertNothingReported();
});

// R8: the booking-GraphQL leg used to be a flat, budget-blind timeout(12),
// making saveSelection()'s worst case the 20 s connect budget PLUS a blind
// 12 s on top. These three pin the clamp against FetchBudget::remaining().

it('clamps the booking-GraphQL timeout to what is left of an open fetch budget', function () {
    $seen = null;
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen = $options['timeout'] ?? null;

        return Http::response(['data' => ['bookingFlowInitialize' => ['screenServices' => ['categories' => []]]]], 200);
    });

    app(FetchBudget::class)->open(3.0, fn () => freshaEmployeeScraper()->fetchEmployeeServices('acme', 'e1'));

    // Would be 12 against unfixed code — the whole point of the unit.
    expect($seen)->toBe(3);
});

it('leaves the timeout at the flat ceiling when no budget is open', function () {
    $seen = null;
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen = $options['timeout'] ?? null;

        return Http::response(['data' => ['bookingFlowInitialize' => ['screenServices' => ['categories' => []]]]], 200);
    });

    // No open() around this call — remaining() must read null ("unbounded"),
    // not 0. This is the concrete no-budget path: RefreshConnectionJob ->
    // PlatformRefresher -> ScheduledRefresh -> FreshaFetch opens no budget.
    freshaEmployeeScraper()->fetchEmployeeServices('acme', 'e1');

    expect($seen)->toBe(12);
});

it('skips the booking-GraphQL call entirely once the fetch budget is exhausted', function () {
    Exceptions::fake();
    Http::fake();

    $result = app(FetchBudget::class)->open(0.0, fn () => freshaEmployeeScraper()->fetchEmployeeServices('acme', 'e1'));

    expect($result)->toBeNull(); // documented fallback contract preserved
    Http::assertNothingSent(); // zero overshoot, not a 1 s doomed request
    Exceptions::assertNothingReported(); // our deadline is not a vendor miss
});
