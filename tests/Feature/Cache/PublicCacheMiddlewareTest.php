<?php

/** @phpstan-ignore-all */

use App\Services\Cache\CacheKeyGenerator;
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
