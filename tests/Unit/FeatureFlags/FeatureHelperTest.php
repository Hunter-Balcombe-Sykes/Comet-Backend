<?php

use App\Services\FeatureFlags\FeatureFlagService;
use Tests\TestCase;

uses(TestCase::class);

it('feature() helper delegates to FeatureFlagService', function () {
    $mock = $this->mock(FeatureFlagService::class);
    $mock->shouldReceive('enabled')->with('test_helper', null)->andReturn(true);
    expect(feature('test_helper'))->toBeTrue();
});
