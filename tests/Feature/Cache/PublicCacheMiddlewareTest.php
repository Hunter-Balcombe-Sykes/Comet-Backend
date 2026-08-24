<?php

/** @phpstan-ignore-all */

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

/**
 * Integration tests proving AddPublicCacheHeaders is active on the correct API routes.
 * These hit the real HTTP stack (withoutMiddleware is NOT used) so the middleware
 * wire-up in bootstrap/app.php is exercised.
 *
 * To avoid touching the real pgsql database (BaseModel::$connection = 'pgsql'),
 * we pre-warm the SiteCacheService cache before making the HTTP request. The
 * service checks the cache first and returns early without ever querying the DB.
 */
it('public site-by-slug route returns Cache-Control: public with CDN TTL when response is 200', function () {
    $subdomain = 'test-cache-'.Str::random(6);
    prewarmSiteCache($subdomain);

    $response = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->getJson('/api/public/site-by-slug');

    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public');
    expect($cacheControl)->toContain('max-age=900');
    expect($cacheControl)->toContain('s-maxage=900');
});

it('public site-by-slug route includes Vary: X-Site-Subdomain in response headers', function () {
    $subdomain = 'test-vary-'.Str::random(6);
    prewarmSiteCache($subdomain);

    $response = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->getJson('/api/public/site-by-slug');

    $response->assertOk();

    $vary = (string) $response->headers->get('Vary', '');
    expect($vary)->toContain('X-Site-Subdomain');
});

it('unsubscribe route returns Cache-Control: no-store regardless of response code', function () {
    // The middleware must set no-store before the route handler resolves,
    // so even a 404 (no token found) must carry the no-store header.
    $response = $this->getJson('/api/public/unsubscribe/abc123token456');

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->not->toContain('public');
});

it('authenticated API routes do not receive public cache headers', function () {
    $response = $this
        ->withHeader('Authorization', 'Bearer fake-token')
        ->getJson('/api/public/site-by-slug');

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->not->toContain('public');
});

/**
 * API-4: the individual public profile route (the Astro Worker's SSR
 * subrequest target) is now allow-listed for public caching alongside
 * site-by-slug. Confirm it gets both Cache-Control and ETag.
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
 * API-4 sub-fix / SEC-1: Vary is now prefix-specific. Profile routes resolve
 * the tenant from the {handle} path segment, not a header, so they must NOT
 * carry Vary: X-Site-Subdomain — only site-by-slug does (see the regression
 * guard test below).
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
 * Regression guard for the per-prefix Vary refactor: site-by-slug must keep
 * varying on X-Site-Subdomain even though profiles routes no longer do.
 */
it('public site-by-slug route still varies on X-Site-Subdomain after the per-prefix Vary refactor', function () {
    $subdomain = 'test-vary-regress-'.Str::random(6);
    prewarmSiteCache($subdomain);

    $response = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->getJson('/api/public/site-by-slug');

    $response->assertOk();

    $vary = (string) $response->headers->get('Vary', '');
    expect($vary)->toContain('X-Site-Subdomain');
});

/**
 * The ordering guard. AddETagHeaders is appended to the `api` group AFTER
 * AddPublicCacheHeaders, so it unwinds first and converts the response to 304
 * before AddPublicCacheHeaders runs — which is the entire cause of the defect.
 * A unit test on the middleware alone cannot catch a regression of it, because
 * in isolation the middleware was always correct. This fails if the pipeline is
 * reordered back.
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

/**
 * SEC-1, and the reason this fix is not merely a performance change. site-by-slug
 * resolves its tenant from the client-supplied X-Site-Subdomain header, so that
 * Vary token is the cache key that keeps tenants apart. Before the 304 fix the
 * revalidation response dropped it (observed on dev as `vary: Origin` alone), and
 * a shared cache is permitted to update a stored entry's headers from a 304 — so
 * the key could be lost on an entry that stays served. Pin it.
 */
it('a conditional GET on site-by-slug returns 304 still carrying Vary: X-Site-Subdomain', function () {
    $subdomain = 'test-304-vary-'.Str::random(6);
    prewarmSiteCache($subdomain);

    $first = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->getJson('/api/public/site-by-slug');
    $first->assertOk();

    $etag = (string) $first->headers->get('ETag', '');
    expect($etag)->not->toBe('');

    $second = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->withHeader('If-None-Match', $etag)
        ->getJson('/api/public/site-by-slug');

    $second->assertStatus(304);
    expect((string) $second->headers->get('Vary', ''))->toContain('X-Site-Subdomain');
});

/**
 * The same ordering hazard as the 304 guard above, now for stale-while-revalidate.
 * AddETagHeaders unwinds first and converts the response to 304 before
 * AddPublicCacheHeaders runs, so a directive emitted only on 200s is silently
 * dropped on every revalidation — and RFC 9111 lets a shared cache update the
 * stored entry's headers from a 304, so that dropped directive un-does itself for
 * the whole stored entry. A unit test cannot catch this: in isolation the
 * middleware is correct, and the ordering only exists in the real pipeline.
 */
it('carries stale-while-revalidate on a 304 revalidation, not just the 200', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 60]);

    $subdomain = 'test-swr-304-'.Str::random(6);
    prewarmSiteCache($subdomain);

    // First request establishes the validator.
    $first = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->getJson('/api/public/site-by-slug');
    $first->assertOk();
    expect((string) $first->headers->get('Cache-Control', ''))
        ->toContain('stale-while-revalidate=60');

    $etag = (string) $first->headers->get('ETag', '');
    expect($etag)->not->toBe('');

    $second = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->withHeader('If-None-Match', $etag)
        ->getJson('/api/public/site-by-slug');

    $second->assertStatus(304);

    $cacheControl = (string) $second->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('stale-while-revalidate=60')
        ->toContain('s-maxage=30')
        ->toContain('public');
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Pre-populate the SiteCacheService cache so the controller returns a 200
 * without touching the pgsql database.
 */
function prewarmSiteCache(string $subdomain): void
{
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    Cache::put($key, [
        'published' => true,
        'site' => [
            'id' => (string) Str::uuid(),
            'subdomain' => $subdomain,
            'is_published' => true,
            'settings' => [],
            'gallery' => [],
            'content_images' => [],
        ],
        'professional' => [
            'id' => (string) Str::uuid(),
            'handle' => $subdomain,
            'display_name' => 'Test Pro',
        ],
        'services' => [],
        'links' => [],
        'sections' => [],
        'blocks' => [],
        'legal' => null,
        'store' => null,
    ], now()->addMinutes(15));
}

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
