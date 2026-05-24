<?php

// B14/P3-03 — ServiceCategory mutations must bust only the four services cache
// keys (professionalDashboardServices + professionalServices, both ± :stale)
// and the public site payload (category titles are embedded there). Prior to
// this fix the observer called invalidateProfessional() which nuked 13+ keys
// including the hydrated User model — forcing unnecessary Postgres round-trips.

use App\Models\Core\Professional\User;
use App\Models\Core\Professional\ServiceCategory;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupServiceCategoriesTable();
});

function seedCategoryTestPro(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'cat-pro',
        'handle_lc' => 'cat-pro',
        'display_name' => 'Cat Pro',
        'account_type' => 'individual',
        'status' => 'active',
    ]);

    return User::query()->findOrFail($id);
}

it('busts only the services cache keys when a ServiceCategory is created', function () {
    $pro = seedCategoryTestPro();

    // Seed stale values so we can assert they were cleared.
    $dashKey = CacheKeyGenerator::professionalDashboardServices($pro->id);
    $svcKey  = CacheKeyGenerator::professionalServices($pro->id);
    Cache::put($dashKey, ['old'], 60);
    Cache::put($dashKey.':stale', ['old-stale'], 60);
    Cache::put($svcKey, ['old'], 60);
    Cache::put($svcKey.':stale', ['old-stale'], 60);

    // SiteCacheService::invalidateSite is called for the site payload — mock it
    // so we don't need a full site fixture. No site → skipped gracefully.
    $siteCache = Mockery::mock(SiteCacheService::class);
    $siteCache->shouldNotReceive('invalidateSite'); // no site on this pro
    app()->instance(SiteCacheService::class, $siteCache);

    ServiceCategory::query()->create([
        'professional_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    expect(Cache::get($dashKey))->toBeNull()
        ->and(Cache::get($dashKey.':stale'))->toBeNull()
        ->and(Cache::get($svcKey))->toBeNull()
        ->and(Cache::get($svcKey.':stale'))->toBeNull();
});

it('busts only the services cache keys when a ServiceCategory is updated', function () {
    $pro = seedCategoryTestPro();
    $category = ServiceCategory::query()->create([
        'professional_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    $dashKey = CacheKeyGenerator::professionalDashboardServices($pro->id);
    $svcKey  = CacheKeyGenerator::professionalServices($pro->id);
    Cache::put($dashKey, ['old'], 60);
    Cache::put($dashKey.':stale', ['old-stale'], 60);
    Cache::put($svcKey, ['old'], 60);
    Cache::put($svcKey.':stale', ['old-stale'], 60);

    $siteCache = Mockery::mock(SiteCacheService::class);
    $siteCache->shouldNotReceive('invalidateSite');
    app()->instance(SiteCacheService::class, $siteCache);

    $category->update(['title' => 'Renamed']);

    expect(Cache::get($dashKey))->toBeNull()
        ->and(Cache::get($dashKey.':stale'))->toBeNull()
        ->and(Cache::get($svcKey))->toBeNull()
        ->and(Cache::get($svcKey.':stale'))->toBeNull();
});

it('busts only the services cache keys when a ServiceCategory is deleted', function () {
    $pro = seedCategoryTestPro();
    $category = ServiceCategory::query()->create([
        'professional_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    $dashKey = CacheKeyGenerator::professionalDashboardServices($pro->id);
    $svcKey  = CacheKeyGenerator::professionalServices($pro->id);
    Cache::put($dashKey, ['old'], 60);
    Cache::put($dashKey.':stale', ['old-stale'], 60);
    Cache::put($svcKey, ['old'], 60);
    Cache::put($svcKey.':stale', ['old-stale'], 60);

    $siteCache = Mockery::mock(SiteCacheService::class);
    $siteCache->shouldNotReceive('invalidateSite');
    app()->instance(SiteCacheService::class, $siteCache);

    $category->delete();

    expect(Cache::get($dashKey))->toBeNull()
        ->and(Cache::get($dashKey.':stale'))->toBeNull()
        ->and(Cache::get($svcKey))->toBeNull()
        ->and(Cache::get($svcKey.':stale'))->toBeNull();
});
