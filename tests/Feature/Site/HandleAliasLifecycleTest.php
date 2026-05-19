<?php

use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\PublicSite\PublicSiteResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('does not resolve sites via expired subdomain aliases', function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupSubdomainAliasesTable();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id'              => $proId,
        'handle'          => 'newhandle',
        'handle_lc'       => 'newhandle',
        'status'          => 'active',
        'primary_email'   => 'newhandle@example.test',
        'professional_type' => 'professional',
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id'              => $siteId,
        'professional_id' => $proId,
        'subdomain'       => 'newhandle',
        'is_published'    => 1,
        'settings'        => json_encode([]),
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    // Expired alias — should NOT resolve.
    SiteSubdomainAlias::create([
        'site_id'       => $siteId,
        'subdomain'     => 'expiredhandle',
        'reclaim_until' => now()->subDays(91),
        'expires_at'    => now()->subDay(),
        'created_at'    => now()->subDays(91),
    ]);

    // Active alias — should resolve.
    SiteSubdomainAlias::create([
        'site_id'       => $siteId,
        'subdomain'     => 'livehandle',
        'reclaim_until' => now()->addDays(5),
        'expires_at'    => now()->addDays(60),
        'created_at'    => now()->subDays(30),
    ]);

    $resolver = app(PublicSiteResolver::class);
    expect($resolver->resolvePublishedSite('newhandle')['site'])->not->toBeNull();
    expect($resolver->resolvePublishedSite('livehandle')['site'])->not->toBeNull();
    expect($resolver->resolvePublishedSite('expiredhandle')['site'])->toBeNull();
});

it('returns 301 to canonical subdomain when showByHeader endpoint is hit via an active alias', function () {
    setupSitesTable();
    setupSubdomainAliasesTable();

    $siteId = (string) Str::uuid();
    $now    = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id'              => $siteId,
        'professional_id' => null,
        'subdomain'       => 'newhandle',
        'is_published'    => 1,
        'settings'        => json_encode([]),
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    SiteSubdomainAlias::create([
        'site_id'       => $siteId,
        'subdomain'     => 'oldhandle',
        'reclaim_until' => now()->addDays(5),
        'expires_at'    => now()->addDays(60),
        'created_at'    => now(),
    ]);

    // Stub the site cache so it always returns a miss — the controller falls through to alias lookup.
    $mockCache = Mockery::mock(\App\Services\Cache\SiteCacheService::class);
    $mockCache->shouldReceive('getPublicSitePayload')->andReturn(null);
    app()->instance(\App\Services\Cache\SiteCacheService::class, $mockCache);

    $response = $this->withHeaders(['X-Site-Subdomain' => 'oldhandle'])
                     ->get('/api/public/site-by-slug');

    $response->assertStatus(301);
});

it('writes alias KV entries with expirationTtl and a type=alias marker', function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupHandleAliasesTable();

    $kv = Mockery::mock(\App\Services\Cloudflare\CloudflareKvService::class);
    $this->app->instance(\App\Services\Cloudflare\CloudflareKvService::class, $kv);

    $proId  = (string) \Illuminate\Support\Str::uuid();
    $now    = now()->toDateTimeString();
    $expiry = now()->addSeconds(7776000)->toDateTimeString(); // 90d

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id'                => $proId,
        'handle'            => 'newh',
        'handle_lc'         => 'newh',
        'status'            => 'active',
        'primary_email'     => 'newh@example.test',
        'professional_type' => 'brand',
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    DB::connection('pgsql')->table('site.professional_handle_aliases')->insert([
        'id'              => (string) \Illuminate\Support\Str::uuid(),
        'professional_id' => $proId,
        'handle'          => 'oldh',
        'reclaim_until'   => now()->addDays(5)->toDateTimeString(),
        'expires_at'      => $expiry,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    // Canonical entry — no expiry (null TTL).
    $kv->shouldReceive('put')->once()->with('newh', ['type' => 'brand'], null);

    // Alias entry — type=alias, target=newh, TTL close to 90d.
    $kv->shouldReceive('put')->once()->withArgs(function ($key, $value, $ttl) {
        return $key === 'oldh'
            && $value === ['type' => 'alias', 'target' => 'newh']
            && $ttl >= 7776000 - 10 && $ttl <= 7776000 + 10;
    });

    (new \App\Jobs\Cloudflare\SyncSubdomainToKvJob($proId))
        ->handle($kv);
});

it('walks a subdomain alias through grace → redirect → released states', function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();

    \Illuminate\Support\Carbon::setTestNow('2026-06-01 12:00:00');

    $proId  = (string) \Illuminate\Support\Str::uuid();
    $siteId = (string) \Illuminate\Support\Str::uuid();
    $start  = '2026-06-01 12:00:00';

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id'               => $proId,
        'handle'           => 'new-handle',
        'handle_lc'        => 'new-handle',
        'status'           => 'active',
        'primary_email'    => 'lifecycle@example.test',
        'professional_type'=> 'professional',
        'created_at'       => $start,
        'updated_at'       => $start,
    ]);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.sites')->insert([
        'id'              => $siteId,
        'professional_id' => $proId,
        'subdomain'       => 'new-handle',
        'is_published'    => 1,
        'created_at'      => $start,
        'updated_at'      => $start,
    ]);

    // Create alias manually (mimics what UpdateSiteAction would create on rename)
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id'            => (string) \Illuminate\Support\Str::uuid(),
        'site_id'       => $siteId,
        'subdomain'     => 'old-handle',
        'reclaim_until' => '2026-06-15 12:00:00',   // +14d = end of GRACE
        'expires_at'    => '2026-08-30 12:00:00',   // +90d = end of REDIRECT
        'created_at'    => $start,
    ]);

    $resolver = app(\App\Services\PublicSite\PublicSiteResolver::class);

    // --- Day 1: GRACE ---
    \Illuminate\Support\Carbon::setTestNow('2026-06-02 12:00:00');
    $result = $resolver->resolvePublishedSite('old-handle');
    expect($result['alias_hit'])->toBeTrue();
    expect($result['site'])->not->toBeNull();

    // Reclaimable within grace window
    expect(\App\Models\Core\Site\SiteSubdomainAlias::where('subdomain', 'old-handle')
        ->reclaimable()->exists())->toBeTrue();

    // --- Day 20: REDIRECT (past reclaim window, still redirects) ---
    \Illuminate\Support\Carbon::setTestNow('2026-06-21 12:00:00');
    $result = $resolver->resolvePublishedSite('old-handle');
    expect($result['alias_hit'])->toBeTrue();
    expect(\App\Models\Core\Site\SiteSubdomainAlias::where('subdomain', 'old-handle')
        ->reclaimable()->exists())->toBeFalse();

    // --- Day 91: RELEASED (after prune) ---
    \Illuminate\Support\Carbon::setTestNow('2026-09-02 12:00:00');
    $this->artisan(\App\Console\Commands\PruneExpiredHandleAliases::class)->assertSuccessful();

    expect(\App\Models\Core\Site\SiteSubdomainAlias::where('subdomain', 'old-handle')->exists())->toBeFalse();
    $result = $resolver->resolvePublishedSite('old-handle');
    expect($result['site'])->toBeNull();
    expect($result['alias_hit'])->toBeFalse();

    \Illuminate\Support\Carbon::setTestNow(); // reset
});
