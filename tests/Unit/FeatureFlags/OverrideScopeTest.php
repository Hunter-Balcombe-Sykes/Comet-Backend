<?php

use App\Services\FeatureFlags\OverrideScope;

it('builds a professional scope', function () {
    $scope = OverrideScope::forProfessional('pro-uuid-1');
    expect($scope->professionalId)->toBe('pro-uuid-1');
});


it('rejects scopes with neither id set', function () {
    OverrideScope::forProfessional('');
})->throws(InvalidArgumentException::class);
