<?php

use App\Services\FeatureFlags\OverrideScope;

it('builds a professional scope', function () {
    $scope = OverrideScope::forUser('pro-uuid-1');
    expect($scope->userId)->toBe('pro-uuid-1');
});

it('rejects scopes with neither id set', function () {
    OverrideScope::forUser('');
})->throws(InvalidArgumentException::class);
