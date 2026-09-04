<?php

/** @phpstan-ignore-all */

use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

/**
 * Integration tests proving AddPublicCacheHeaders is active on the correct API routes.
 * These hit the real HTTP stack (withoutMiddleware is NOT used) so the middleware
 * wire-up in bootstrap/app.php is exercised.
 *
 * To avoid touching the real pgsql database (BaseModel::$connection = 'pgsql'),
 * profile-route tests mock CacheLockService/IndividualProfilePayloadBuilder via
 * bindPublicProfileCache() below so the controller returns a 200 without ever
 * querying the DB.
 */
it('unsubscribe route returns Cache-Control: no-store regardless of response code', function () {
    // The middleware must set no-store before the route handler resolves,
    // so even a 404 (no token found) must carry the no-store header.
    $response = $this->getJson('/api/public/unsubscribe/abc123token456');

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->not->toContain('public');
});

it('authenticated API routes do not receive public cache headers', function () {
    bindPublicProfileCache([
        ['pro_id' => 'p1', 'site_id' => 's1', 'updated_at_ts' => 123],
        ['profile' => ['handle' => 'jane']],
    ]);

    $response = $this
        ->withHeader('Authorization', 'Bearer fake-token')
        ->getJson('/api/public/profiles/jane');

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->not->toContain('public');
});

/**
 * API-4: the individual public profile route (the Astro Worker's SSR
 * subrequest target) is the sole allow-listed public-cacheable route since
 * the header-tenanted /public/site-by-slug lane was retired 2026-09-04.
 * Confirm it gets both Cache-Control and ETag.
 */
it('public profiles route returns Cache-Control: public with CDN TTL and an ETag when response is 200', function () {
    bindPublicProfileCache([
        ['pro_id' => 'p1', 'site_id' => 's1', 'updated_at_ts' => 123],
        ['profile' => ['handle' => 'jane']],
    ]);

    $response = $this->getJson('/api/public/profiles/jane');

    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public');
    expect($cacheControl)->toContain('max-age=5');
    expect($cacheControl)->toContain('s-maxage=5');
    expect($response->headers->has('ETag'))->toBeTrue();
});

/**
 * SEC-1 (retained rail, not currently exercised by a second tenant): Vary is
 * prefix-specific so a future public route that resolves its tenant from a
 * client-supplied header does not silently inherit DEFAULT_VARY and leak one
 * tenant's cached response to another — see VARY_BY_PREFIX's docblock in
 * AddPublicCacheHeaders. Profile routes resolve the tenant from the {handle}
 * path segment, not a header, so they must NOT carry Vary: X-Site-Subdomain.
 * (Before 2026-09-04 this was also a live regression guard for
 * /public/site-by-slug, which DID resolve its tenant from that header; that
 * route is retired and the guard went with it.)
 */
it('public profiles route Vary includes Accept-Encoding but not X-Site-Subdomain', function () {
    bindPublicProfileCache([
        ['pro_id' => 'p1', 'site_id' => 's1', 'updated_at_ts' => 123],
        ['profile' => ['handle' => 'jane']],
    ]);

    $response = $this->getJson('/api/public/profiles/jane');

    $response->assertOk();

    $vary = (string) $response->headers->get('Vary', '');
    expect($vary)->toContain('Accept-Encoding');
    expect($vary)->not->toContain('X-Site-Subdomain');
});

/**
 * The ordering guard. AddETagHeaders is appended to the `api` group AFTER
 * AddPublicCacheHeaders, so it unwinds first and converts the response to 304
 * before AddPublicCacheHeaders runs — which is the entire cause of the defect.
 * A unit test on the middleware alone cannot catch a regression of it, because
 * in isolation the middleware was always correct. This fails if the pipeline is
 * reordered back. This is also the surviving coverage that a 304 does not drop
 * Vary (see the retired site-by-slug SEC-1 guard note above): line below
 * confirms Vary: Accept-Encoding survives the 304 for the one live cacheable
 * route.
 */
it('a conditional GET on the profile route returns 304 still carrying the public cache contract', function () {
    $resolve = ['pro_id' => 'p1', 'site_id' => 's1', 'updated_at_ts' => 123];
    $payload = ['profile' => ['handle' => 'jane']];

    // Four values: two HTTP requests x two rememberLocked() calls each. Mockery
    // repeats the LAST value once exhausted, so supplying only two would hand the
    // second request the payload where it expects the resolve map.
    bindPublicProfileCache([$resolve, $payload, $resolve, $payload]);

    $first = $this->getJson('/api/public/profiles/jane');
    $first->assertOk();

    $etag = (string) $first->headers->get('ETag', '');
    expect($etag)->not->toBe('');

    $second = $this
        ->withHeader('If-None-Match', $etag)
        ->getJson('/api/public/profiles/jane');

    $second->assertStatus(304);

    $cacheControl = (string) $second->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public')
        ->toContain('s-maxage=5');
    expect((string) $second->headers->get('Vary', ''))->toContain('Accept-Encoding');
});

// The stale-while-revalidate-on-a-304 test that stood here ("carries
// stale-while-revalidate on a 304 revalidation, not just the 200") exercised
// /public/site-by-slug, retired 2026-09-04. It cannot be repointed at
// api/public/profiles instead: AddPublicCacheHeaders::handle() hardcodes
// $swr = 0 for the 'api/public/profiles' prefix unconditionally (it never
// reads config('partna.cache.public_swr') for that prefix — see the
// `if ($prefix === 'api/public/profiles')` branch), and CACHEABLE_PATH_PREFIXES
// now has no other entry. So no live route can currently ever emit
// stale-while-revalidate, and there is nothing left for this test to assert
// against without inventing a route the app doesn't have. The ordering-hazard
// mechanism this guarded (a directive emitted only on 200s being silently
// dropped on a 304) is unchanged and would still apply the day a future
// cacheable prefix is added with swr > 0 — this comment is the flag that its
// test coverage needs to be re-added at that time.

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Bind a mocked CacheLockService whose rememberLocked() returns the given
 * values on successive calls (1st = handle.resolve, 2nd = payload build),
 * plus a builder stub — mirrors bindProfileCache() in
 * IndividualProfileControllerTest.php (kept local/renamed here to avoid a
 * global function name clash when the full suite runs both files).
 *
 * @param  array<int, mixed>  $returns
 */
function bindPublicProfileCache(array $returns): void
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
