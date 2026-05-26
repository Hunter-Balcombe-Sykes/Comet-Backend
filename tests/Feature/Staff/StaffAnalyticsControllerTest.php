<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Models\Core\User\User;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;

beforeEach(function () {
    Cache::flush();
    setupProfessionalsTable();
    setupSitesTable();

    $this->userId = (string) Str::uuid();
    $this->siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $this->userId,
        'display_name' => 'Analytics Test Pro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $this->siteId,
        'user_id' => $this->userId,
        'subdomain' => 'analytics-test',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->user = User::find($this->userId);
});

afterEach(function () {
    Mockery::close();
});

// Wire up DB mocks for all analytics table queries in summary(). Uses DB::shouldReceive
// so the test doesn't require live analytics data or PostgreSQL-specific SQL (::text casts).
function mockAnalyticsDb(): void
{
    $visitsAgg = (object) ['total_visits' => 3, 'unique_visitors' => 2, 'last_visit_at' => null];
    $visitsByDay = collect([(object) ['day' => '2026-05-17', 'count' => 3]]);
    $clicksAgg = (object) ['total_clicks' => 1, 'unique_clickers' => 1, 'last_click_at' => null];

    // Handles both the aggregate query (->first()) and daily chart (->get()).
    $visitsQuery = Mockery::mock();
    $visitsQuery->shouldReceive('where', 'whereBetween', 'selectRaw', 'groupByRaw', 'orderBy')->andReturnSelf();
    $visitsQuery->shouldReceive('first')->andReturn($visitsAgg);
    $visitsQuery->shouldReceive('get')->andReturn($visitsByDay);

    $clicksQuery = Mockery::mock();
    $clicksQuery->shouldReceive('where', 'whereBetween', 'selectRaw', 'groupByRaw', 'orderBy')->andReturnSelf();
    $clicksQuery->shouldReceive('first')->andReturn($clicksAgg);
    $clicksQuery->shouldReceive('get')->andReturn(collect());

    // Top links query — different table alias + join.
    $topLinksQuery = Mockery::mock();
    $topLinksQuery->shouldReceive('join', 'where', 'whereBetween', 'whereRaw', 'selectRaw', 'groupBy', 'orderByDesc', 'limit')->andReturnSelf();
    $topLinksQuery->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('analytics.site_visits')->andReturn($visitsQuery);
    DB::shouldReceive('table')->with('analytics.link_clicks')->andReturn($clicksQuery);
    DB::shouldReceive('table')->with('analytics.link_clicks as lc')->andReturn($topLinksQuery);
}

it('summary() returns correct shape and zero-data totals', function () {
    mockAnalyticsDb();

    $controller = new StaffAnalyticsController(new CacheLockService);
    $response = $controller->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', ['days' => 7]),
        $this->user
    );

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true);

    expect($data)->toHaveKeys(['range', 'professional', 'site', 'totals', 'charts', 'top_links'])
        ->and($data['totals']['visits'])->toBe(3)
        ->and($data['totals']['clicks'])->toBe(1)
        ->and($data['professional']['id'])->toBe($this->userId)
        ->and($data['site']['subdomain'])->toBe('analytics-test');
});

// CACHE-3 regression guard: summary() must delegate all DB reads through
// CacheLockService::rememberLocked with a 60s TTL so staff requests don't
// scan raw event tables on every call.
it('summary() wraps all DB queries in CacheLockService::rememberLocked with a 60s TTL (CACHE-3)', function () {
    $this->mock(CacheLockService::class, function (MockInterface $m) use (&$captured) {
        $m->shouldReceive('rememberLocked')
            ->once()
            ->withArgs(function (string $key, int $ttl, \Closure $fn): bool {
                return str_contains($key, 'staff:analytics:summary:') && $ttl === 60;
            })
            ->andReturn([
                'range' => ['from' => '2026-04-17', 'to' => '2026-05-17'],
                'professional' => ['id' => $this->userId, 'handle' => null, 'display_name' => 'Analytics Test Pro', 'professional_type' => null],
                'site' => ['id' => $this->siteId, 'subdomain' => 'analytics-test', 'published' => true],
                'totals' => ['visits' => 0, 'unique_visitors' => 0, 'clicks' => 0, 'unique_clickers' => 0, 'ctr_percent' => 0.0, 'last_visit_at' => null, 'last_click_at' => null],
                'charts' => ['visits_by_day' => [], 'clicks_by_day' => []],
                'top_links' => [],
            ]);
    });

    $response = app(StaffAnalyticsController::class)->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', ['days' => 30]),
        $this->user
    );

    expect($response->getStatusCode())->toBe(200);
    expect(json_decode($response->getContent(), true))->toHaveKey('totals');
});

it('summary() returns 404 when professional has no site', function () {
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'display_name' => 'No Site Pro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $professional = User::find($userId);
    $controller = new StaffAnalyticsController(new CacheLockService);
    $response = $controller->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET'),
        $professional
    );

    expect($response->getStatusCode())->toBe(404);
    expect(json_decode($response->getContent(), true)['message'])->toBe('professional has no site.');
});

it('summary() returns 422 for an invalid date format', function () {
    $controller = new StaffAnalyticsController(new CacheLockService);
    $response = $controller->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', ['from' => 'not-a-date']),
        $this->user
    );

    expect($response->getStatusCode())->toBe(422);
});

it('summary() returns 422 when from is after to', function () {
    $controller = new StaffAnalyticsController(new CacheLockService);
    $response = $controller->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', [
            'from' => '2026-05-17',
            'to' => '2026-04-01',
        ]),
        $this->user
    );

    expect($response->getStatusCode())->toBe(422);
});
