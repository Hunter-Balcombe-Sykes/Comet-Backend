<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\User\User;
use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;

beforeEach(function () {
    FeatureFlagTestCase::boot();
});

/**
 * Insert a professional row and return the model.
 */
function makePro(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'pro-'.substr($id, 0, 8),
        'status' => 'active',
    ]);

    return User::find($id);
}

it('same pro+key always buckets identically', function () {
    FeatureFlag::create(['key' => 'ramp_flag', 'default_enabled' => false, 'rollout_percent' => 50]);
    $pro = makePro();
    $service = app(FeatureFlagService::class);

    $first = $service->enabled('ramp_flag', $pro);
    for ($i = 0; $i < 10; $i++) {
        expect($service->enabled('ramp_flag', $pro))->toBe($first);
    }
});

it('ramping percent up never removes a tenant who was previously enabled', function () {
    FeatureFlag::create(['key' => 'ramp_flag', 'default_enabled' => false, 'rollout_percent' => 25]);
    $service = app(FeatureFlagService::class);

    $pros = collect(range(1, 200))->map(fn () => makePro());

    $enabledAt25 = $pros->filter(fn ($p) => $service->enabled('ramp_flag', $p));

    FeatureFlag::where('key', 'ramp_flag')->update(['rollout_percent' => 50]);
    $service->flush();

    $enabledAt50 = $pros->filter(fn ($p) => $service->enabled('ramp_flag', $p));

    foreach ($enabledAt25 as $pro) {
        expect($enabledAt50->contains('id', $pro->id))->toBeTrue();
    }
});

it('distributes roughly evenly at 50 percent', function () {
    FeatureFlag::create(['key' => 'dist_flag', 'default_enabled' => false, 'rollout_percent' => 50]);
    $service = app(FeatureFlagService::class);

    $pros = collect(range(1, 1000))->map(fn () => makePro());

    $enabledCount = $pros->filter(fn ($p) => $service->enabled('dist_flag', $p))->count();
    expect($enabledCount)->toBeBetween(450, 550);
});
