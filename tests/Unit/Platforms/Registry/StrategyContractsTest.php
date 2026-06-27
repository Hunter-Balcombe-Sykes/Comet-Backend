<?php

use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\OAuthConnect;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

it('defines the three strategy contracts', function () {
    expect(interface_exists(FetchStrategy::class))->toBeTrue();
    expect(interface_exists(RefreshStrategy::class))->toBeTrue();
    expect(interface_exists(ConnectStrategy::class))->toBeTrue();
});

it('declares OAuth/webhook seams without implementations', function () {
    expect(interface_exists(OAuthConnect::class))->toBeTrue();
    // No concrete class implements the seam in this plan.
    $implementors = collect(get_declared_classes())
        ->filter(fn ($c) => is_subclass_of($c, OAuthConnect::class));
    expect($implementors)->toBeEmpty();
});
