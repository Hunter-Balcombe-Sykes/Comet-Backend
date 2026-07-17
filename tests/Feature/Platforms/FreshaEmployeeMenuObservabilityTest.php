<?php

// OBS-1: FreshaScraper::fetchEmployeeServices() previously degraded to a silent
// Log::warning + null on all 3 failure branches — invisible to Nightwatch, while
// FreshaFetch quietly falls back to the whole-location menu and the circuit
// breaker records 'ok'. This pins the fix: each branch now ALSO reports a
// FreshaEmployeeMenuUnavailableException, while the null-return + fallback
// contract stays byte-identical (purely additive).

use App\Exceptions\Platforms\FreshaEmployeeMenuUnavailableException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\FreshaScraper;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

function freshaEmployeeScraper(): FreshaScraper
{
    return new FreshaScraper(Mockery::mock(SafeUrlFetcher::class));
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
