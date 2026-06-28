<?php

use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Models\Core\User\User;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

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

it('carries a live connect strategy and its error message', function () {
    $d = PlatformDescriptor::linkOnly(
        'x', 'X', LinkConnectionResource::class,
    )->connect(
        new UrlConnect(
            fn (string $input): ?array => $input === 'good' ? ['username' => 'good', 'url' => 'https://x.com/good'] : null,
        ),
        'Enter your X handle or profile URL (x.com/yourname).',
    );

    expect($d->connectStrategy())->toBeInstanceOf(ConnectStrategy::class);
    expect($d->connectStrategy()->normalize('good'))->toBe(['username' => 'good', 'url' => 'https://x.com/good']);
    expect($d->connectStrategy()->normalize('bad'))->toBeNull();
    expect($d->connectErrorMessage())->toBe('Enter your X handle or profile URL (x.com/yourname).');
});

it('returns null connect accessors before a strategy is attached', function () {
    $d = PlatformDescriptor::make('linkedin');

    expect($d->connectStrategy())->toBeNull();
    expect($d->connectErrorMessage())->toBeNull();
});
