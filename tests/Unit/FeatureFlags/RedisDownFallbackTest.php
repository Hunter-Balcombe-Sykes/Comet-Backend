<?php

use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'cache_unavailable'));
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
    $proId = (string) \Illuminate\Support\Str::uuid();

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
        'id' => (string) \Illuminate\Support\Str::uuid(),
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
        'display_name' => 'Override Pro',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));

    $pro = new \App\Models\Core\User\User;
    $pro->id = $proId;
    $pro->status = 'active';

    $service = app(FeatureFlagService::class);
    $result = $service->allFor($pro);

    // Base flag is off globally but the pro override flips it on.
    expect($result)->toHaveKey('base_flag');
    expect($result['base_flag'])->toBeTrue();
});
