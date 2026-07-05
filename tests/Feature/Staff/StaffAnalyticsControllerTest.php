<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Models\Core\User\User;
use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;

beforeEach(function () {
    Cache::flush();
    setupUsersTable();
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

// Mocks AnalyticsQueryService for the aggregate + chart + top_links reads that
// AnalyticsCacheService::composeStaff() delegates to — the controller itself no
// longer touches DB directly (FOUND-1: routed through the same service the
// professional dashboard uses, so behaviour parity is structural, not incidental).
function mockStaffAnalyticsQueries(): MockInterface
{
    return test()->mock(AnalyticsQueryService::class, function (MockInterface $m) {
        $visitsAgg = (object) ['total_visits' => 3, 'unique_visitors' => 2, 'last_visit_at' => null];
        $clicksAgg = (object) ['total_clicks' => 1, 'unique_clickers' => 1, 'last_click_at' => null];

        $m->shouldReceive('visitsAggregate')->andReturn($visitsAgg);
        $m->shouldReceive('clicksAggregate')->andReturn($clicksAgg);
        $m->shouldReceive('visitsByBucket')->andReturn(collect([(object) ['day' => '2026-05-17', 'count' => 3]]));
        $m->shouldReceive('clicksByBucket')->andReturn(collect());
        $m->shouldReceive('topLinks')->andReturn(collect());
    });
}

it('summary() returns correct shape and zero-data totals', function () {
    mockStaffAnalyticsQueries();

    $controller = app(StaffAnalyticsController::class);
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

// CACHE-3 regression guard: staffSummary() must delegate through CacheLockService::
// rememberLocked with a 60s TTL, same as the legacy controller's cache-key contract,
// so staff requests don't scan raw event tables on every call. Caching now lives in
// AnalyticsCacheService rather than the controller — mock it there and resolve the
// controller from the container so the mock flows through constructor injection.
it('staffSummary() wraps the composed payload in CacheLockService::rememberLocked with a 60s TTL (CACHE-3)', function () {
    $this->mock(CacheLockService::class, function (MockInterface $m) {
        $m->shouldReceive('rememberLocked')
            ->once()
            ->withArgs(function (string $key, int $ttl, Closure $fn): bool {
                return str_contains($key, 'staff:analytics:summary:') && $ttl === 60;
            })
            ->andReturn([
                'range' => ['from' => '2026-04-17', 'to' => '2026-05-17'],
                'professional' => ['id' => $this->userId, 'handle' => null, 'display_name' => 'Analytics Test Pro'],
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
    $controller = new StaffAnalyticsController(app(AnalyticsCacheService::class));
    $response = $controller->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET'),
        $professional
    );

    expect($response->getStatusCode())->toBe(404);
    expect(json_decode($response->getContent(), true)['message'])->toBe('professional has no site.');
});

it('summary() returns 422 for an invalid date format', function () {
    $controller = new StaffAnalyticsController(app(AnalyticsCacheService::class));
    $response = $controller->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', ['from' => 'not-a-date']),
        $this->user
    );

    expect($response->getStatusCode())->toBe(422);
});

it('summary() returns 422 when from is after to', function () {
    $controller = new StaffAnalyticsController(app(AnalyticsCacheService::class));
    $response = $controller->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', [
            'from' => '2026-05-17',
            'to' => '2026-04-01',
        ]),
        $this->user
    );

    expect($response->getStatusCode())->toBe(422);
});

// Refactor guard (P3-24): aggregate reads go through AnalyticsQueryService scoped to
// the TARGET professional's user_id (not the staff actor's own id).
it('summary() routes visit and click aggregates through AnalyticsQueryService scoped to the target professional', function () {
    $this->mock(AnalyticsQueryService::class, function (MockInterface $m) {
        $visitsAgg = (object) ['total_visits' => 7, 'unique_visitors' => 5, 'last_visit_at' => null];
        $clicksAgg = (object) ['total_clicks' => 2, 'unique_clickers' => 2, 'last_click_at' => null];

        $m->shouldReceive('visitsAggregate')
            ->once()
            ->withArgs(fn (string $uid) => $uid === $this->userId)
            ->andReturn($visitsAgg);

        $m->shouldReceive('clicksAggregate')
            ->once()
            ->withArgs(fn (string $uid) => $uid === $this->userId)
            ->andReturn($clicksAgg);

        $m->shouldReceive('visitsByBucket')->andReturn(collect([(object) ['day' => '2026-06-01', 'count' => 7]]));
        $m->shouldReceive('clicksByBucket')->andReturn(collect());
        $m->shouldReceive('topLinks')->andReturn(collect());
    });

    $response = app(StaffAnalyticsController::class)->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', ['days' => 7]),
        $this->user
    );

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true);

    // Shape is preserved; service-supplied aggregates flow through unchanged.
    expect($data)->toHaveKeys(['range', 'professional', 'site', 'totals', 'charts', 'top_links'])
        ->and($data['totals']['visits'])->toBe(7)
        ->and($data['totals']['unique_visitors'])->toBe(5)
        ->and($data['totals']['clicks'])->toBe(2)
        ->and($data['totals']['ctr_percent'])->toBe(round((2 / 7) * 100, 2))
        ->and($data['professional']['id'])->toBe($this->userId);
});

// FOUND-1 regression guard: top_links now comes from AnalyticsQueryService::topLinks()
// (v2 self-describing url/platform/label/section_key rows), not a site.blocks INNER
// JOIN keyed on link_block_id — which silently dropped every v2 click since
// link_block_id is NULL for url-based clicks.
it('summary() surfaces v2 url-based top_links via AnalyticsQueryService::topLinks() (FOUND-1)', function () {
    $this->mock(AnalyticsQueryService::class, function (MockInterface $m) {
        $visitsAgg = (object) ['total_visits' => 1, 'unique_visitors' => 1, 'last_visit_at' => null];
        $clicksAgg = (object) ['total_clicks' => 1, 'unique_clickers' => 1, 'last_click_at' => null];

        $m->shouldReceive('visitsAggregate')->andReturn($visitsAgg);
        $m->shouldReceive('clicksAggregate')->andReturn($clicksAgg);
        $m->shouldReceive('visitsByBucket')->andReturn(collect());
        $m->shouldReceive('clicksByBucket')->andReturn(collect());

        // A v2 click with no backing site.blocks row (link_block_id IS NULL) —
        // this is exactly what the old INNER JOIN dropped.
        $m->shouldReceive('topLinks')->once()->andReturn(collect([
            (object) ['url' => 'https://instagram.com/example', 'platform' => 'instagram', 'label' => 'Instagram', 'section_key' => 'links', 'clicks' => 4],
        ]));
    });

    $response = app(StaffAnalyticsController::class)->summary(
        Request::create('/api/staff/professionals/{pro}/analytics', 'GET', ['days' => 7]),
        $this->user
    );

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true);

    expect($data['top_links'])->toHaveCount(1)
        ->and($data['top_links'][0]['url'])->toBe('https://instagram.com/example')
        ->and($data['top_links'][0]['platform'])->toBe('instagram')
        ->and($data['top_links'][0]['clicks'])->toBe(4);
});
