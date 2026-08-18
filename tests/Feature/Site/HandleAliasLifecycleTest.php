<?php

use App\Console\Commands\PruneExpiredHandleAliases;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Cache\SiteCacheService;
use App\Services\Cloudflare\CloudflareKvService;
use App\Services\PublicSite\PublicSiteResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('does not resolve sites via expired subdomain aliases', function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'newhandle',
        'handle_lc' => 'newhandle',
        'display_name' => 'Newhandle',
        'first_name' => 'Newhandle',
        'status' => 'active',
        'primary_email' => 'newhandle@example.test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'newhandle',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Expired alias — should NOT resolve.
    SiteSubdomainAlias::create([
        'site_id' => $siteId,
        'subdomain' => 'expiredhandle',
        'reclaim_until' => now()->subDays(91),
        'expires_at' => now()->subDay(),
        'created_at' => now()->subDays(91),
    ]);

    // Active alias — should resolve.
    SiteSubdomainAlias::create([
        'site_id' => $siteId,
        'subdomain' => 'livehandle',
        'reclaim_until' => now()->addDays(5),
        'expires_at' => now()->addDays(60),
        'created_at' => now()->subDays(30),
    ]);

    $resolver = app(PublicSiteResolver::class);
    expect($resolver->resolvePublishedSite('newhandle')['site'])->not->toBeNull();
    expect($resolver->resolvePublishedSite('livehandle')['site'])->not->toBeNull();
    expect($resolver->resolvePublishedSite('expiredhandle')['site'])->toBeNull();
});

it('does not resolve an active alias whose canonical site is unpublished', function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'unpub',
        'handle_lc' => 'unpub',
        'display_name' => 'Unpub',
        'first_name' => 'Unpub',
        'status' => 'active',
        'primary_email' => 'unpub@example.test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Canonical site is UNPUBLISHED — an active alias to it must not resolve.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'unpub',
        'is_published' => 0,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    SiteSubdomainAlias::create([
        'site_id' => $siteId,
        'subdomain' => 'aliasx',
        'reclaim_until' => now()->addDays(5),
        'expires_at' => now()->addDays(60),
        'created_at' => now(),
    ]);

    $result = app(PublicSiteResolver::class)->resolvePublishedSite('aliasx');

    expect($result['site'])->toBeNull();
    expect($result['alias_hit'])->toBeFalse();
});

it('returns 301 to canonical subdomain when showByHeader endpoint is hit via an active alias', function () {
    setupSitesTable();
    setupSubdomainAliasesTable();

    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        // site.sites.user_id is NOT NULL in prod — the alias path never reads the user.
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'newhandle',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    SiteSubdomainAlias::create([
        'site_id' => $siteId,
        'subdomain' => 'oldhandle',
        'reclaim_until' => now()->addDays(5),
        'expires_at' => now()->addDays(60),
        'created_at' => now(),
    ]);

    // Stub the site cache so it always returns a miss — the controller falls through to alias lookup.
    $mockCache = Mockery::mock(SiteCacheService::class);
    $mockCache->shouldReceive('getPublicSitePayload')->andReturn(null);
    app()->instance(SiteCacheService::class, $mockCache);

    $response = $this->withHeaders(['X-Site-Subdomain' => 'oldhandle'])
        ->get('/api/public/site-by-slug');

    $response->assertStatus(301)
        // EDGE-1/PGR-17: CFG-3's 5-minute re-check window is now a hardcoded
        // no-cache directive — see the matching comment in show()'s test file.
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, private');
});

it('PGR-17: the showByHeader() alias 301 is never cached', function () {
    setupSitesTable();
    setupSubdomainAliasesTable();

    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        // site.sites.user_id is NOT NULL in prod — the alias path never reads the user.
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'newhandle2',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    SiteSubdomainAlias::create([
        'site_id' => $siteId,
        'subdomain' => 'oldhandle2',
        'reclaim_until' => now()->addDays(5),
        'expires_at' => now()->addDays(60),
        'created_at' => now(),
    ]);

    $mockCache = Mockery::mock(SiteCacheService::class);
    $mockCache->shouldReceive('getPublicSitePayload')->andReturn(null);
    app()->instance(SiteCacheService::class, $mockCache);

    $response = $this->withHeaders(['X-Site-Subdomain' => 'oldhandle2'])
        ->get('/api/public/site-by-slug');

    $response->assertStatus(301)
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, private');
});

it('writes alias KV entries with expirationTtl and a type=alias marker', function () {
    setupUsersTable();
    setupSitesTable();
    setupHandleAliasesTable();

    $kv = Mockery::mock(CloudflareKvService::class);
    $this->app->instance(CloudflareKvService::class, $kv);

    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    $expiry = now()->addSeconds(7776000)->toDateTimeString(); // 90d

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'newh',
        'handle_lc' => 'newh',
        'display_name' => 'Newh',
        'first_name' => 'Newh',
        'status' => 'active',
        'primary_email' => 'newh@example.test',
        'account_type' => 'partna',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'handle' => 'oldh',
        'reclaim_until' => now()->addDays(5)->toDateTimeString(),
        'expires_at' => $expiry,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Canonical entry — no expiry (null TTL).
    $kv->shouldReceive('put')->once()->with('newh', ['type' => 'individual'], null);

    // Alias entries — written via a single bulkPut (SCALE-6). Each entry carries
    // type=alias, redirect=canonical full URL, expiration_ttl close to 90d.
    // The Worker reads entry.redirect as a full URL; the older {target:<handle>}
    // shape caused a 522 self-loop (see SyncSubdomainToKvJob docblock).
    $kv->shouldReceive('bulkPut')->once()->withArgs(function ($entries) {
        return count($entries) === 1
            && $entries[0]['key'] === 'oldh'
            && $entries[0]['value'] === ['type' => 'alias', 'redirect' => 'https://newh.partna.au']
            && $entries[0]['expiration_ttl'] >= 7776000 - 10
            && $entries[0]['expiration_ttl'] <= 7776000 + 10;
    });

    (new SyncSubdomainToKvJob($proId))
        ->handle($kv);
});

it('walks a subdomain alias through grace → redirect → released states', function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();

    Carbon::setTestNow('2026-06-01 12:00:00');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $start = '2026-06-01 12:00:00';

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'new-handle',
        'handle_lc' => 'new-handle',
        'display_name' => 'New-handle',
        'first_name' => 'New-handle',
        'status' => 'active',
        'primary_email' => 'lifecycle@example.test',
        'created_at' => $start,
        'updated_at' => $start,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'new-handle',
        'is_published' => 1,
        'created_at' => $start,
        'updated_at' => $start,
    ]);

    // Create alias manually (mimics what UpdateSiteAction would create on rename)
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'subdomain' => 'old-handle',
        'reclaim_until' => '2026-06-15 12:00:00',   // +14d = end of GRACE
        'expires_at' => '2026-08-30 12:00:00',   // +90d = end of REDIRECT
        'created_at' => $start,
    ]);

    $resolver = app(PublicSiteResolver::class);

    // --- Day 1: GRACE ---
    Carbon::setTestNow('2026-06-02 12:00:00');
    $result = $resolver->resolvePublishedSite('old-handle');
    expect($result['alias_hit'])->toBeTrue();
    expect($result['site'])->not->toBeNull();

    // Reclaimable within grace window
    expect(SiteSubdomainAlias::where('subdomain', 'old-handle')
        ->reclaimable()->exists())->toBeTrue();

    // --- Day 20: REDIRECT (past reclaim window, still redirects) ---
    Carbon::setTestNow('2026-06-21 12:00:00');
    $result = $resolver->resolvePublishedSite('old-handle');
    expect($result['alias_hit'])->toBeTrue();
    expect(SiteSubdomainAlias::where('subdomain', 'old-handle')
        ->reclaimable()->exists())->toBeFalse();

    // --- Day 91: RELEASED (after prune) ---
    Carbon::setTestNow('2026-09-02 12:00:00');
    $this->artisan(PruneExpiredHandleAliases::class)->assertSuccessful();

    expect(SiteSubdomainAlias::where('subdomain', 'old-handle')->exists())->toBeFalse();
    $result = $resolver->resolvePublishedSite('old-handle');
    expect($result['site'])->toBeNull();
    expect($result['alias_hit'])->toBeFalse();

    Carbon::setTestNow(); // reset
});
