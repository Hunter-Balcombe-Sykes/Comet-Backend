<?php

use App\Http\Controllers\Api\PublicSite\IndividualProfileController;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Http\Request;

// TEST-1: the public-profile API is the Astro Worker's subrequest target — the
// single most-read endpoint — and had zero coverage. These tests drive the
// controller's branches through a mocked CacheLockService so they exercise the
// resolve → payload → deleted-race → 200 flow without the full
// site.public_site_payload DB view.

/**
 * Bind a CacheLockService whose rememberLocked() returns the given values on
 * successive calls (1st = handle.resolve, 2nd = payload build), plus a builder
 * stub so the controller never needs the real (DB-backed) payload builder.
 *
 * @param  array<int, mixed>  $returns
 */
function bindProfileCache(array $returns): void
{
    $cache = Mockery::mock(CacheLockService::class);
    $cache->shouldReceive('rememberLocked')->andReturn(...$returns);
    app()->instance(CacheLockService::class, $cache);

    $builder = Mockery::mock(IndividualProfilePayloadBuilder::class);
    $builder->shouldReceive('cacheTtl')->andReturn(60);
    // CCH-5: the controller asks whether the build it just cached was degraded.
    $builder->shouldReceive('lastBuildDegraded')->andReturn(false);
    app()->instance(IndividualProfilePayloadBuilder::class, $builder);
}

it('returns 404 for an unknown handle (the not_found sentinel)', function () {
    // Only the resolve lookup runs; it returns the not-found sentinel → 404.
    bindProfileCache([['not_found' => true]]);

    $this->getJson('/api/public/profiles/ghost')->assertStatus(404);
});

it('returns 404 when the profile row is deleted between resolve and build', function () {
    // resolve points at a row; the payload build returns null (row vanished) →
    // the deleted-race path busts both keys and 404s.
    bindProfileCache([
        ['pro_id' => 'p1', 'site_id' => null, 'updated_at_ts' => 123],
        null,
    ]);

    $this->getJson('/api/public/profiles/jane')->assertStatus(404);
});

it('returns a 200 data-enveloped payload for a known valid handle (cache-miss path)', function () {
    $payload = ['profile' => ['handle' => 'jane'], 'site' => ['id' => 's1']];

    bindProfileCache([
        ['pro_id' => 'p1', 'site_id' => 's1', 'updated_at_ts' => 123],
        $payload,
    ]);

    // The {'data': ...} envelope is the Astro Worker contract — assert it exactly.
    $this->getJson('/api/public/profiles/jane')
        ->assertOk()
        ->assertJson(['data' => $payload]);
});

it('returns 404 for a blank handle (controller guard — unreachable via the route constraint)', function () {
    // The route's [A-Za-z0-9-]+ constraint means a whitespace handle never routes
    // here, so exercise the $handleLc === '' guard directly. The cache must never
    // be consulted.
    $cache = Mockery::mock(CacheLockService::class);
    $cache->shouldNotReceive('rememberLocked');
    $builder = Mockery::mock(IndividualProfilePayloadBuilder::class);

    $controller = new IndividualProfileController($cache, $builder);
    $response = $controller->show(Request::create('/api/public/profiles/x', 'GET'), '   ');

    expect($response->getStatusCode())->toBe(404);
});
