<?php

// CACHE-6: SmartLinkObserver must call invalidateUser() with bustSite: false so that
// the touch() that always follows (SiteObserver → invalidateSite) is the ONLY site
// cache bust. Prior to this fix, bustSite defaulted to true, causing invalidateSite()
// to fire twice — once inside invalidateUser() AND once via the SiteObserver chain —
// double-busting ~29 Redis keys on every smart-link write. Mirrors ServiceObserver (B7/WAMP-3).
//
// Testing strategy: spy on UserCacheService to assert bustSite: false is passed.
// Assert CloudflareCachePurgeJob dispatch separately to verify the SiteObserver chain fires.

use App\Models\Core\Site\SmartLink;
use App\Services\Cache\UserCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    Queue::fake();
});

function seedSmartLinkBustPro(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'sl-bust-pro',
        'handle_lc' => 'sl-bust-pro',
        'display_name' => 'SmartLink Bust Pro',
        'account_type' => 'individual',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'sl-bust-pro',
        'is_published' => false,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $link = SmartLink::query()->create([
        'user_id' => $proId,
        'site_id' => $siteId,
        'family' => 'link',
        'type' => 'url',
        'platform' => 'other',
        'canonical_url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    return [$proId, $siteId, $link];
}

it('calls invalidateUser with bustSite: false on a smart-link save', function () {
    // Bind spy BEFORE seed so it is injected when the create observer fires.
    $cacheSpy = Mockery::spy(UserCacheService::class);
    app()->instance(UserCacheService::class, $cacheSpy);

    [, , $link] = seedSmartLinkBustPro();

    $link->update(['canonical_url' => 'https://updated.example.com']);

    // Both create and update fire runHooks; the update path must pass bustSite: false.
    $cacheSpy->shouldHaveReceived('invalidateUser')
        ->withArgs(fn ($pro, $bustSite = true) => $bustSite === false)
        ->atLeast()
        ->once();
});

it('calls invalidateUser with bustSite: false on a smart-link delete', function () {
    $cacheSpy = Mockery::spy(UserCacheService::class);
    app()->instance(UserCacheService::class, $cacheSpy);

    [, , $link] = seedSmartLinkBustPro();

    $link->delete();

    $cacheSpy->shouldHaveReceived('invalidateUser')
        ->withArgs(fn ($pro, $bustSite = true) => $bustSite === false)
        ->atLeast()
        ->once();
});

it('dispatches CloudflareCachePurgeJob via SiteObserver touch() chain on smart-link save', function () {
    // Suppress the real UserCacheService (no Redis in tests) without preventing touch().
    $cacheSpy = Mockery::spy(UserCacheService::class);
    app()->instance(UserCacheService::class, $cacheSpy);

    [, , $link] = seedSmartLinkBustPro();

    Queue::fake(); // Reset — only count jobs from the update below.

    $link->update(['canonical_url' => 'https://updated.example.com']);

    Queue::assertPushed(\App\Jobs\Cloudflare\CloudflareCachePurgeJob::class, function ($job) {
        return $job->handle === 'sl-bust-pro';
    });
});
