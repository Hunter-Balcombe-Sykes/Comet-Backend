<?php

use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
    FeatureFlagTestCase::boot();
});

it('returns false for a dotted key that would traverse nested config via interpolation', function () {
    // If config('partna.features.foo.bar') were used, a $key of 'foo' would
    // resolve to the 'foo' sub-array (truthy), not a boolean flag value.
    // Array access config('partna.features')[$key] prevents traversal.
    config(['partna.features' => ['foo' => ['bar' => true]]]);

    $service = app(FeatureFlagService::class);

    // 'foo' maps to an array — should not be cast as a truthy bool flag.
    // With dot-path: config('partna.features.foo') returns ['bar'=>true] → (bool) = true (WRONG).
    // With array access: config('partna.features')['foo'] = ['bar'=>true] → (bool) = true too,
    // but the important case is 'foo.bar' — dot-path would traverse, array access returns null → false.
    expect($service->enabled('foo.bar'))->toBeFalse();
});

it('still resolves a valid simple flag key from the config fallback', function () {
    config(['partna.features' => ['my_flag' => true]]);

    $service = app(FeatureFlagService::class);

    expect($service->enabled('my_flag'))->toBeTrue();
});

it('returns false for a key absent from the features config', function () {
    config(['partna.features' => []]);

    $service = app(FeatureFlagService::class);

    expect($service->enabled('nonexistent_flag'))->toBeFalse();
});
