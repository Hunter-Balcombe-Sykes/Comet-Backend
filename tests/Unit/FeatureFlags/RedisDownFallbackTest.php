<?php

use App\Models\Core\User\User;
use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
    FeatureFlagTestCase::boot();
});

it('falls back to DB when cache throws and logs a warning', function () {
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    Log::spy();

    $service = app(FeatureFlagService::class);
    config(['partna.features.fallback_flag' => true]);

    expect($service->enabled('fallback_flag'))->toBeTrue();

    // D1: the breadcrumb for a dead STORE now comes from CacheLockService, one
    // layer down, not from this service's own catch. The registry lookup goes
    // through rememberLocked(), which since D1 degrades to an uncached compute
    // instead of rethrowing — so enabled() gets its DB answer without the
    // exception ever reaching FeatureFlagService::enabled()'s catch.
    //
    // The alarm is NOT lost, it moved and widened: every CacheLockService
    // consumer now emits this, rather than only the handful that hand-wrote a
    // catch. The service's own guard is still live for the faults it uniquely
    // sees — proven by the next test, not assumed here.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg) => $msg === 'cache.store_unavailable')
        ->atLeast()->once();
});

it('still logs its own feature_flags.cache_unavailable when the fault is its own', function () {
    // loadProOverrides() does a bare Cache::get() of its own BEFORE reaching
    // rememberLocked (the warm-read fast path that skips the MIN(expires_at)
    // query). That call is outside CacheLockService, so it still bubbles — and
    // this service's context-rich breadcrumb, with flag_key and user_id, still
    // fires. Pinned so the D1 layering change above cannot quietly become
    // "FeatureFlagService's catch is dead code".
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    Log::spy();

    $pro = new User;
    $pro->id = (string) Str::uuid();
    $pro->status = 'active';

    $service = app(FeatureFlagService::class);
    config(['partna.features.fallback_flag' => true]);

    expect($service->enabled('fallback_flag', $pro))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg) => $msg === 'feature_flags.cache_unavailable')
        ->atLeast()->once();
});

// FFLAG-2: enabled() degraded path — DB row present, Redis down.
// The existing test above only exercises config fallback (no DB row). This one
// seeds a real FeatureFlag row so enabled() reads its default_enabled value
// from the DB query in allForFromDb(), not just config('partna.features.*').
it('enabled() returns the DB flag value (not config) when Redis is down and a FeatureFlag row exists', function () {
    // Seed a flag that is enabled=true in the DB.
    DB::connection('pgsql')->table('core.feature_flags')->insert([
        'key' => 'db_backed_flag',
        'description' => 'test flag',
        'default_enabled' => 1,
        'rollout_percent' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Ensure config says false so we prove the result comes from the DB, not config.
    config(['partna.features.db_backed_flag' => false]);

    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));

    $service = app(FeatureFlagService::class);

    // The DB row says enabled=true; config says false — result must be true (DB wins).
    expect($service->enabled('db_backed_flag'))->toBeTrue();
});

// FFLAG-1: allFor() degraded path — DB rows + user override, Redis down.
// allFor() loads registry + pro overrides via allForFromDb() when cache throws.
// This test verifies the merged result: base flag from DB, user override wins.
it('allFor() returns merged DB registry + user override when Redis is down', function () {
    $proId = (string) Str::uuid();

    // Seed a base flag: default_enabled=false.
    DB::connection('pgsql')->table('core.feature_flags')->insert([
        'key' => 'base_flag',
        'description' => 'base flag off by default',
        'default_enabled' => 0,
        'rollout_percent' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Seed a user override: enabled=true for this pro.
    DB::connection('pgsql')->table('core.feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_key' => 'base_flag',
        'user_id' => $proId,
        'enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Seed the user row (required by allForFromDb's whereExists on core.users).
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'override-pro',
        'handle_lc' => 'override-pro',
        'display_name' => 'Override Pro',
        'first_name' => 'Override',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));

    $pro = new User;
    $pro->id = $proId;
    $pro->status = 'active';

    $service = app(FeatureFlagService::class);
    $result = $service->allFor($pro);

    // Base flag is off globally but the pro override flips it on.
    expect($result)->toHaveKey('base_flag');
    expect($result['base_flag'])->toBeTrue();
});
