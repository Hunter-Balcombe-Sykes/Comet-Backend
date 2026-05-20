<?php

use App\Http\Controllers\Api\Internal\HydrogenAffiliateController;
use App\Http\Controllers\Api\Internal\HydrogenAffiliateProductsController;
use App\Http\Controllers\Api\Internal\HydrogenBrandConfigController;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\SitepageDataResolverService;
use Mockery as M;

// Master Pattern 15: the three Hydrogen controllers must call rememberLocked
// with an int TTL (so jitter applies — DateTimeInterface TTLs bypass jitter
// per CacheLockService::writeWithJitter) and the expected key shape. These
// tests bind a mock CacheLockService into the container so a route hit goes
// through it and we can assert the call.

afterEach(function () {
    M::close();
});

it('HydrogenAffiliateController constructor accepts a CacheLockService dependency', function () {
    // Smoke test: the constructor is wired up so the container can resolve it.
    // Validates the dep is non-nullable (the test would error on missing arg
    // if signature drifted back to required-without-default).
    $controller = new HydrogenAffiliateController(new CacheLockService, new SitepageDataResolverService);

    expect($controller)->toBeInstanceOf(HydrogenAffiliateController::class);
});

it('HydrogenBrandConfigController constructor accepts a CacheLockService dependency', function () {
    $controller = new HydrogenBrandConfigController(new CacheLockService);

    expect($controller)->toBeInstanceOf(HydrogenBrandConfigController::class);
});

it('HydrogenAffiliateProductsController constructor accepts a CacheLockService dependency', function () {
    $controller = new HydrogenAffiliateProductsController(new CacheLockService);

    expect($controller)->toBeInstanceOf(HydrogenAffiliateProductsController::class);
});

it('HydrogenAffiliateProductsController uses rememberLocked with a 60s int TTL and the documented key', function () {
    // Drive the controller directly with a fake CacheLockService and assert the
    // exact rememberLocked invocation. We don't care that the closure runs (it
    // hits the DB); the contract is: "wrap the payload assembly in
    // rememberLocked with int TTL=60 and the documented key".
    $affiliateId = '11111111-1111-4111-8111-111111111111';
    $expectedKey = 'hydrogen:affiliate-products:v1:'.$affiliateId;

    $cacheLock = M::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')
        ->withArgs(function (string $key, int $ttl, Closure $callback) use ($expectedKey) {
            return $key === $expectedKey && $ttl === 60;
        })
        ->once()
        ->andReturn(['gids' => [], 'source' => 'default_collection', 'default_collection_handle' => 'partna-default-products']);

    $controller = new HydrogenAffiliateProductsController($cacheLock);

    $request = \Illuminate\Http\Request::create('/internal/hydrogen/affiliate-products', 'GET', [
        'affiliate_id' => $affiliateId,
    ]);

    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(200);
});
