<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupFeatureFlagsTable();
});

it('deletes expired overrides, keeps active ones', function () {
    $proId = (string) Str::uuid();
    $pro2Id = (string) Str::uuid();
    $pro3Id = (string) Str::uuid();

    DB::connection('pgsql')->table('core.feature_flags')->insert([
        'key' => 'prune_test_flag', 'default_enabled' => false, 'rollout_percent' => 0,
        'description' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Expired pro override
    $expiredId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feature_flag_overrides')->insert([
        'id' => $expiredId, 'flag_key' => 'prune_test_flag', 'professional_id' => $proId,
        'enabled' => true, 'expires_at' => now()->subMinute(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Active pro override
    $activeId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feature_flag_overrides')->insert([
        'id' => $activeId, 'flag_key' => 'prune_test_flag', 'professional_id' => $pro3Id,
        'enabled' => true, 'expires_at' => now()->addHour(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Permanent override (no expires_at)
    $permanentId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feature_flag_overrides')->insert([
        'id' => $permanentId, 'flag_key' => 'prune_test_flag', 'professional_id' => $pro2Id,
        'enabled' => false, 'expires_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('feature-flags:prune-expired')->assertExitCode(0);

    expect(DB::connection('pgsql')
        ->table('core.feature_flag_overrides')->where('id', $expiredId)->exists())->toBeFalse();

    expect(DB::connection('pgsql')
        ->table('core.feature_flag_overrides')->where('id', $activeId)->exists())->toBeTrue();

    expect(DB::connection('pgsql')
        ->table('core.feature_flag_overrides')->where('id', $permanentId)->exists())->toBeTrue();

    // Cleanup
    DB::connection('pgsql')->table('core.feature_flag_overrides')
        ->whereIn('id', [$activeId, $permanentId])->delete();
    DB::connection('pgsql')->table('core.feature_flags')->where('key', 'prune_test_flag')->delete();
});
