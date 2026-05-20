<?php

// §28.17 CACHE-1 — ServiceCategory mutations must trigger
// ProfessionalCacheService::invalidateProfessional so the dashboard
// services cache doesn't serve stale category state for up to the
// 30-min TTL after a rename / delete / reorder.

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ServiceCategory;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupServiceCategoriesTable();
});

function seedCategoryTestPro(): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'cat-pro',
        'handle_lc' => 'cat-pro',
        'display_name' => 'Cat Pro',
        'professional_type' => 'professional',
        'account_type' => 'individual',
        'status' => 'active',
    ]);

    return Professional::query()->findOrFail($id);
}

it('invalidates the Professional cache when a ServiceCategory is created', function () {
    $pro = seedCategoryTestPro();

    $cache = Mockery::mock(ProfessionalCacheService::class);
    $cache->shouldReceive('invalidateProfessional')->once()->with(Mockery::on(fn ($p) => $p->id === $pro->id));
    app()->instance(ProfessionalCacheService::class, $cache);

    ServiceCategory::query()->create([
        'professional_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);
});

it('invalidates the Professional cache when a ServiceCategory is updated', function () {
    $pro = seedCategoryTestPro();
    $category = ServiceCategory::query()->create([
        'professional_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    $cache = Mockery::mock(ProfessionalCacheService::class);
    $cache->shouldReceive('invalidateProfessional')->once()->with(Mockery::on(fn ($p) => $p->id === $pro->id));
    app()->instance(ProfessionalCacheService::class, $cache);

    $category->update(['title' => 'Renamed']);
});

it('invalidates the Professional cache when a ServiceCategory is deleted', function () {
    $pro = seedCategoryTestPro();
    $category = ServiceCategory::query()->create([
        'professional_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    $cache = Mockery::mock(ProfessionalCacheService::class);
    $cache->shouldReceive('invalidateProfessional')->once()->with(Mockery::on(fn ($p) => $p->id === $pro->id));
    app()->instance(ProfessionalCacheService::class, $cache);

    $category->delete();
});
