<?php

// tests/Unit/Analytics/AnalyticsFaultNotCachedTest.php
//
// #OBS-1 — a genuine DB fault in one of the eight read methods that used to
// swallow QueryException must now bubble all the way out of
// AnalyticsCacheService::summary()/staffSummary(), and CacheLockService::
// rememberLocked() must not have written anything (neither the primary key nor
// its ':stale' sibling) before the throw. Previously the catch blocks turned a
// broken ingestion table into a cached zero for up to 24h.

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    Cache::flush();
});

it('does not cache a false-zero when clicksAggregate throws mid-summary() (mocked)', function () {
    // visitsAggregate succeeds; clicksAggregate (the second call in compose())
    // throws — everything after it, including the Cache::put in rememberLocked,
    // must never run.
    $this->mock(AnalyticsQueryService::class, function (MockInterface $m) {
        $m->shouldReceive('visitsAggregate')
            ->andReturn((object) ['total_visits' => 5, 'unique_visitors' => 3, 'last_visit_at' => null]);
        $m->shouldReceive('clicksAggregate')
            ->andThrow(new QueryException('sqlite', 'select 1', [], new Exception('boom')));
    });

    // Real AnalyticsCacheService + real CacheLockService (array cache store,
    // configured in phpunit.xml) — nothing here is mocked except the query service.
    $cacheService = app(AnalyticsCacheService::class);

    $userId = (string) Str::uuid();
    $professional = new User;
    $professional->id = $userId;
    $professional->handle = 'fault-test';
    $professional->display_name = 'Fault Test Pro';

    $site = new Site;
    $site->id = (string) Str::uuid();
    $site->subdomain = 'fault-test';
    $site->is_published = true;

    $from = Carbon::parse('2026-07-01 00:00:00');
    $to = Carbon::parse('2026-07-02 00:00:00');
    $useHourlyBuckets = false;

    expect(fn () => $cacheService->summary($professional, $site, $from, $to, $useHourlyBuckets, 'Australia/Sydney'))
        ->toThrow(QueryException::class);

    // Reconstruct the exact key summary() builds — see AnalyticsCacheService::summary().
    $summaryVersion = (int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion($userId), 0);
    $cacheKey = CacheKeyGenerator::analyticsSummary($userId, $from->format('YmdH'), $to->format('YmdH'))
        .':'.($useHourlyBuckets ? 'hour' : 'day')
        .":v{$summaryVersion}";

    expect(Cache::has($cacheKey))->toBeFalse()
        ->and(Cache::has($cacheKey.':stale'))->toBeFalse();
});

it('a dropped analytics.link_clicks table makes staffSummary() throw without caching a false-zero (real SQL, SQLite)', function () {
    setupUsersTable();
    setupSitesTable();
    setupSiteVisitsTable();
    setupLinkClicksTable();

    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'display_name' => 'Real Fault Test Pro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'real-fault-test',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // composeStaff() runs visitsAggregate() (analytics.site_visits — still present)
    // then clicksAggregate() (analytics.link_clicks — gone). This mirrors a real
    // broken/missing ingestion table, not a mocked exception.
    DB::connection('pgsql')->statement('DROP TABLE analytics.link_clicks');

    $professional = User::find($userId);
    $site = Site::find($siteId);

    $from = Carbon::now()->subDays(7);
    $to = Carbon::now();

    $cacheService = app(AnalyticsCacheService::class);

    expect(fn () => $cacheService->staffSummary($professional, $site, $from, $to))
        ->toThrow(QueryException::class);

    // Reconstruct the exact key staffSummary() builds — see AnalyticsCacheService::staffSummary().
    $version = (int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion($userId), 0);
    $cacheKey = CacheKeyGenerator::staffAnalyticsSummary($userId, $from->toDateString(), $to->toDateString()).":v{$version}";

    expect(Cache::has($cacheKey))->toBeFalse()
        ->and(Cache::has($cacheKey.':stale'))->toBeFalse();
});

it('clicksAggregate() now propagates a QueryException instead of swallowing it and returning zeros (behaviour lock)', function () {
    setupLinkClicksTable();
    DB::connection('pgsql')->statement('DROP TABLE analytics.link_clicks');

    $service = app(AnalyticsQueryService::class);

    expect(fn () => $service->clicksAggregate((string) Str::uuid(), Carbon::now()->subDay(), Carbon::now()))
        ->toThrow(QueryException::class);
});
