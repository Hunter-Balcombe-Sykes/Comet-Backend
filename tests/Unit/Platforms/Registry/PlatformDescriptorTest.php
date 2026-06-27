<?php

use App\Models\Core\User\User;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;

it('builds a descriptor via the fluent builder', function () {
    $d = PlatformDescriptor::make('fresha')
        ->label('Fresha')
        ->category(PlatformCategory::Booking)
        ->resource('App\\Http\\Resources\\Platforms\\FreshaSelectionResource')
        ->refreshable(false);

    expect($d->key())->toBe('fresha');
    expect($d->getLabel())->toBe('Fresha');
    expect($d->getCategory())->toBe(PlatformCategory::Booking);
    expect($d->resourceClass())->toBe('App\\Http\\Resources\\Platforms\\FreshaSelectionResource');
    expect($d->isRefreshable())->toBeFalse();
});

it('linkOnly preset sets social category, non-refreshable, given resource', function () {
    $d = PlatformDescriptor::linkOnly('linkedin', 'LinkedIn', 'App\\Http\\Resources\\Platforms\\LinkConnectionResource');
    expect($d->getCategory())->toBe(PlatformCategory::Social);
    expect($d->isRefreshable())->toBeFalse();
    expect($d->resourceClass())->toBe('App\\Http\\Resources\\Platforms\\LinkConnectionResource');
});

it('availableFor defaults to true for any user', function () {
    $d = PlatformDescriptor::linkOnly('x', 'X', 'App\\Http\\Resources\\Platforms\\LinkConnectionResource');
    expect($d->availableFor(new User))->toBeTrue();
});
