<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffStatsController;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Stats are cached for 60s under a shared key — flush so each test exercises
    // the underlying DB queries instead of a value left over from a sibling test.
    Cache::flush();
});

it('returns correct shape with zero data', function () {
    $profQuery = Mockery::mock();
    $profQuery->shouldReceive('whereNull')->andReturnSelf();
    $profQuery->shouldReceive('selectRaw')->andReturnSelf();
    $profQuery->shouldReceive('groupByRaw')->andReturnSelf();
    $profQuery->shouldReceive('pluck')->andReturn(collect());

    $subQuery = Mockery::mock();
    $subQuery->shouldReceive('whereNull')->andReturnSelf();
    $subQuery->shouldReceive('count')->andReturn(0);

    $commQuery = Mockery::mock();
    $commQuery->shouldReceive('where')->andReturnSelf();
    $commQuery->shouldReceive('sum')->andReturn(0);

    DB::shouldReceive('table')->with('core.professionals')->andReturn($profQuery);
    DB::shouldReceive('table')->with('billing.subscriptions')->andReturn($subQuery);
    DB::shouldReceive('table')->with('commerce.commission_movements')->andReturn($commQuery);

    $controller = new StaffStatsController(new CacheLockService);
    $response = $controller->show(Request::create('/', 'GET'));
    $data = json_decode($response->getContent(), true);

    expect($data)->toHaveKeys(['professionals', 'subscriptions', 'commissions'])
        ->and($data['professionals'])->toHaveKeys(['total', 'by_account_type'])
        ->and($data['professionals'])->not->toHaveKey('brands')
        ->and($data['professionals']['total'])->toBe(0)
        ->and($data['subscriptions']['active_count'])->toBe(0)
        ->and($data['commissions']['pending_cents'])->toBe(0);
});

it('sums account-type counts correctly', function () {
    $profQuery = Mockery::mock();
    $profQuery->shouldReceive('whereNull')->andReturnSelf();
    $profQuery->shouldReceive('selectRaw')->andReturnSelf();
    $profQuery->shouldReceive('groupByRaw')->andReturnSelf();
    $profQuery->shouldReceive('pluck')->andReturn(collect([
        'brand' => '3',
        'partner' => '8',
        'individual' => '12',
    ]));

    $subQuery = Mockery::mock();
    $subQuery->shouldReceive('whereNull')->andReturnSelf();
    $subQuery->shouldReceive('count')->andReturn(8);

    $commQuery = Mockery::mock();
    $commQuery->shouldReceive('where')->andReturnSelf();
    $commQuery->shouldReceive('sum')->andReturn(150000);

    DB::shouldReceive('table')->with('core.professionals')->andReturn($profQuery);
    DB::shouldReceive('table')->with('billing.subscriptions')->andReturn($subQuery);
    DB::shouldReceive('table')->with('commerce.commission_movements')->andReturn($commQuery);

    $controller = new StaffStatsController(new CacheLockService);
    $response = $controller->show(Request::create('/', 'GET'));
    $data = json_decode($response->getContent(), true);

    expect($data['professionals']['by_account_type'])->toBe([
        'brand' => '3',
        'partner' => '8',
        'individual' => '12',
    ])
        ->and($data['professionals']['total'])->toBe(23)
        ->and($data['subscriptions']['active_count'])->toBe(8)
        ->and($data['commissions']['pending_cents'])->toBe(150000);
});

it('caches the stats payload across calls', function () {
    $profQuery = Mockery::mock();
    $profQuery->shouldReceive('whereNull')->andReturnSelf();
    $profQuery->shouldReceive('selectRaw')->andReturnSelf();
    $profQuery->shouldReceive('groupByRaw')->andReturnSelf();
    // Single pluck per cache-miss (now one breakdown only); second call served from cache.
    $profQuery->shouldReceive('pluck')->once()->andReturn(collect(['brand' => '1']));

    $subQuery = Mockery::mock();
    $subQuery->shouldReceive('whereNull')->andReturnSelf();
    $subQuery->shouldReceive('count')->once()->andReturn(0);

    $commQuery = Mockery::mock();
    $commQuery->shouldReceive('where')->andReturnSelf();
    $commQuery->shouldReceive('sum')->once()->andReturn(0);

    DB::shouldReceive('table')->with('core.professionals')->andReturn($profQuery);
    DB::shouldReceive('table')->with('billing.subscriptions')->andReturn($subQuery);
    DB::shouldReceive('table')->with('commerce.commission_movements')->andReturn($commQuery);

    $controller = new StaffStatsController(new CacheLockService);
    $controller->show(Request::create('/', 'GET'));
    $controller->show(Request::create('/', 'GET'));
});
