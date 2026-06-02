<?php

// B7/WAMP-3 — ServiceObserver must fire SiteCacheService::invalidateSite() exactly once
// per service save. Prior to this fix, invalidateUser() called invalidateSite() at its
// tail AND touchParentSite() fired SiteObserver → invalidateSite() a second time,
// resulting in ~29 wasted Redis DELs on every service mutation (the most frequent write
// path in the app).

use App\Models\Core\User\User;
use App\Models\Core\User\Service;
use App\Services\Cache\SiteCacheService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupSubdomainAliasesTable();
    Queue::fake();

    mock(SectionVisibilityService::class)->shouldIgnoreMissing();
});

function seedServiceBustPro(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'svc-bust-pro',
        'handle_lc' => 'svc-bust-pro',
        'display_name' => 'Svc Bust Pro',
        'account_type' => 'individual',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'svc-bust-pro',
        'is_published' => false,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $service = Service::query()->create([
        'user_id' => $proId,
        'title' => 'Original Title',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    return [$proId, $siteId, $service];
}

it('fires invalidateSite exactly once on a service title edit', function () {
    [, , $service] = seedServiceBustPro();

    $spy = Mockery::spy(SiteCacheService::class);
    app()->instance(SiteCacheService::class, $spy);

    $service->update(['title' => 'New Title']);

    $spy->shouldHaveReceived('invalidateSite')->once();
});

it('fires invalidateSite exactly once on a service delete', function () {
    [, , $service] = seedServiceBustPro();

    $spy = Mockery::spy(SiteCacheService::class);
    app()->instance(SiteCacheService::class, $spy);

    $service->delete();

    $spy->shouldHaveReceived('invalidateSite')->once();
});
