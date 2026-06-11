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
