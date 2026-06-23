<?php

// B7/WAMP-3 — ServiceObserver must fire SiteCacheService::invalidateSite() exactly once
// per service save. Prior to this fix, invalidateUser() called invalidateSite() at its
// tail AND touchParentSite() fired SiteObserver → invalidateSite() a second time,
// resulting in ~29 wasted Redis DELs on every service mutation (the most frequent write
// path in the app).

use App\Models\Core\User\Service;
use App\Services\Cache\SiteCacheService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

// P3-27: reevaluateBooking() must be gated on visibility-affecting column changes.
// sort_order-only saves should cache-bust (invalidateSite fires) but NOT trigger
// SectionVisibilityService::reevaluateEnabled (extra DB queries).

it('does NOT call reevaluateEnabled on a sort_order-only update', function () {
    [, , $service] = seedServiceBustPro();

    $visibilitySpy = Mockery::spy(SectionVisibilityService::class);
    app()->instance(SectionVisibilityService::class, $visibilitySpy);

    $service->update(['sort_order' => 5]);

    $visibilitySpy->shouldNotHaveReceived('reevaluateEnabled');
});

it('DOES call reevaluateEnabled on an is_active change', function () {
    [, , $service] = seedServiceBustPro();

    $visibilitySpy = Mockery::spy(SectionVisibilityService::class);
    app()->instance(SectionVisibilityService::class, $visibilitySpy);

    $service->update(['is_active' => false]);

    $visibilitySpy->shouldHaveReceived('reevaluateEnabled')->atLeast()->once();
});

it('DOES call reevaluateEnabled on service create', function () {
    [$proId] = seedServiceBustPro();

    $visibilitySpy = Mockery::spy(SectionVisibilityService::class);
    app()->instance(SectionVisibilityService::class, $visibilitySpy);

    Service::query()->create([
        'user_id' => $proId,
        'title' => 'Brand new service',
        'is_active' => true,
        'sort_order' => 10,
    ]);

    $visibilitySpy->shouldHaveReceived('reevaluateEnabled')->atLeast()->once();
});

it('DOES call reevaluateEnabled on service delete', function () {
    [, , $service] = seedServiceBustPro();

    $visibilitySpy = Mockery::spy(SectionVisibilityService::class);
    app()->instance(SectionVisibilityService::class, $visibilitySpy);

    $service->delete();

    $visibilitySpy->shouldHaveReceived('reevaluateEnabled')->atLeast()->once();
});
