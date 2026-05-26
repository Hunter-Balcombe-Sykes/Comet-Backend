<?php

use App\Services\FeatureFlags\FeatureFlagService;

uses(Tests\TestCase::class);

it('feature() helper delegates to FeatureFlagService', function () {
    $mock = $this->mock(FeatureFlagService::class);
    $mock->shouldReceive('enabled')->with('test_helper', null)->andReturn(true);
    expect(feature('test_helper'))->toBeTrue();
});
