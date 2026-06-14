<?php

use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// TEST-2: PublicSiteController::show (the domain-routed loader) diverges from the
// tested showByHeader path — it only 301s when the canonical payload is actually
// cached, and 404s otherwise. The 301-happy path is covered by SubdomainChangeTest
// (view-backed); these tests cover the UNCOVERED divergences: a published-but-
// evicted canonical and an expired alias must 404, not redirect.

function seedCanonicalSiteWithAlias(string $aliasSub, string $canonicalSub, callable $aliasTimes): string
{
    setupSitesTable();
    setupSubdomainAliasesTable();

    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => null,
        'subdomain' => $canonicalSub,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    SiteSubdomainAlias::create(array_merge([
        'site_id' => $siteId,
        'subdomain' => $aliasSub,
        'created_at' => now(),
    ], $aliasTimes()));

    return $siteId;
}

it('301s to the canonical host when an active alias resolves and the canonical payload is cached', function () {
    $domain = config('partna.public_domain');
    seedCanonicalSiteWithAlias('oldhandle', 'newhandle', fn () => [
        'reclaim_until' => now()->addDays(5),
        'expires_at' => now()->addDays(60),
    ]);

    // Miss for the alias subdomain, hit for the canonical → redirect.
    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')->with('oldhandle')->andReturn(null);
    $cache->shouldReceive('getPublicSitePayload')->with('newhandle')->andReturn(['site' => ['id' => 'x']]);
    app()->instance(SiteCacheService::class, $cache);

    $this->get('http://oldhandle.'.$domain.'/api/public/site')
        ->assertStatus(301)
        ->assertRedirect('http://newhandle.'.$domain.'/api/public/site');
});

it('returns 404 (not a redirect) when an active alias resolves but the canonical payload is evicted', function () {
    // This is the divergence from showByHeader, which 301s on alias resolution
    // regardless of cache warmth. show() requires the canonical to be cached.
    $domain = config('partna.public_domain');
    seedCanonicalSiteWithAlias('oldhandle', 'newhandle', fn () => [
        'reclaim_until' => now()->addDays(5),
        'expires_at' => now()->addDays(60),
    ]);

    // Both the alias subdomain AND the canonical return a cache miss.
    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')->andReturn(null);
    app()->instance(SiteCacheService::class, $cache);

    $this->get('http://oldhandle.'.$domain.'/api/public/site')->assertStatus(404);
});

it('returns 404 (not a redirect) for an expired subdomain alias', function () {
    // The ->active() scope must exclude the lapsed alias even before the prune
    // job hard-deletes it — a lapsed alias 404s rather than 301s.
    $domain = config('partna.public_domain');
    seedCanonicalSiteWithAlias('oldhandle', 'newhandle', fn () => [
        'reclaim_until' => now()->subDays(80),
        'expires_at' => now()->subDay(),
    ]);

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')->andReturn(null);
    app()->instance(SiteCacheService::class, $cache);

    $this->get('http://oldhandle.'.$domain.'/api/public/site')->assertStatus(404);
});
