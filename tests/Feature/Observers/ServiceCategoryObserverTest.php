<?php

// B14/P3-03 — ServiceCategory mutations must bust only the four services cache
// keys (professionalDashboardServices + professionalServices, both ± :stale)
// and the public site payload (category titles are embedded there). Prior to
// this fix the observer called invalidateUser() which nuked 13+ keys
// including the hydrated User model — forcing unnecessary Postgres round-trips.

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use App\Models\Core\User\ServiceCategory;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
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

    ServiceCategory::query()->create([
        'user_id' => $pro->id,
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
        'user_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    $dashKey = CacheKeyGenerator::professionalDashboardServices($pro->id);
    $svcKey  = CacheKeyGenerator::professionalServices($pro->id);
    Cache::put($dashKey, ['old'], 60);
    Cache::put($dashKey.':stale', ['old-stale'], 60);
    Cache::put($svcKey, ['old'], 60);
    Cache::put($svcKey.':stale', ['old-stale'], 60);

    $category->update(['title' => 'Renamed']);

    expect(Cache::get($dashKey))->toBeNull()
        ->and(Cache::get($dashKey.':stale'))->toBeNull()
        ->and(Cache::get($svcKey))->toBeNull()
        ->and(Cache::get($svcKey.':stale'))->toBeNull();
});

it('busts only the services cache keys when a ServiceCategory is deleted', function () {
    $pro = seedCategoryTestPro();
    $category = ServiceCategory::query()->create([
        'user_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    $dashKey = CacheKeyGenerator::professionalDashboardServices($pro->id);
    $svcKey  = CacheKeyGenerator::professionalServices($pro->id);
    Cache::put($dashKey, ['old'], 60);
    Cache::put($dashKey.':stale', ['old-stale'], 60);
    Cache::put($svcKey, ['old'], 60);
    Cache::put($svcKey.':stale', ['old-stale'], 60);

    $category->delete();

    expect(Cache::get($dashKey))->toBeNull()
        ->and(Cache::get($dashKey.':stale'))->toBeNull()
        ->and(Cache::get($svcKey))->toBeNull()
        ->and(Cache::get($svcKey.':stale'))->toBeNull();
});

it('touches the site so a ServiceCategory change purges the Cloudflare edge', function () {
    $pro = seedCategoryTestPro();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'subdomain' => 'cat-pro',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Queue::fake();

    ServiceCategory::query()->create([
        'user_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'cat-pro';
    });
});
