<?php

// tests/Unit/Analytics/AnalyticsQueryServiceConversionsTest.php
//
// #OBS-5 — conversions() must log a breadcrumb (not swallow silently) when either
// underlying table's count query throws. Forces a real QueryException by dropping
// the table out from under the query (same technique as SubdomainAvailabilityTest)
// rather than mocking the DB facade's fluent builder chain.

use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupEnquiriesTable();
    setupEmailSubscriptionsTable();
});

it('logs analytics.conversion_query_failed and zeroes enquiries when that table query throws', function () {
    Log::spy();
    DB::connection('pgsql')->statement('DROP TABLE site.enquiries');

    $result = app(AnalyticsQueryService::class)->conversions('user-1', Carbon::now()->subDay(), Carbon::now());

    // Fail-open: the subscribers count still succeeds independently.
    expect($result)->toBe(['enquiries' => 0, 'subscribers' => 0]);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'analytics.conversion_query_failed'
            && $ctx['user_id'] === 'user-1'
            && str_contains($ctx['method'], 'AnalyticsQueryService::conversions'));
});

it('logs analytics.conversion_query_failed and zeroes subscribers when that table query throws', function () {
    Log::spy();
    DB::connection('pgsql')->statement('DROP TABLE notifications.email_subscriptions');

    $result = app(AnalyticsQueryService::class)->conversions('user-1', Carbon::now()->subDay(), Carbon::now());

    expect($result)->toBe(['enquiries' => 0, 'subscribers' => 0]);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'analytics.conversion_query_failed'
            && $ctx['user_id'] === 'user-1'
            && str_contains($ctx['method'], 'AnalyticsQueryService::conversions'));
});
