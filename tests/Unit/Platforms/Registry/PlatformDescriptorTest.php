<?php

use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\Payloads\LinkPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Illuminate\Support\Str;

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

// CAP-1 regression anchor — single-call-site seam.
// availableFor() is currently wired at EXACTLY ONE production site:
// GenericPlatformController::connect. Making it return false for any user
// would silently break that gate without wiring the ~20 bespoke connect flows
// or the public render path. This test forces that wiring conversation to happen
// before the predicate changes. See PlatformDescriptor::availableFor() docblock
// for the full wiring checklist (including the mandatory 404-not-403 rule on the
// public endpoint).
it('availableFor is a single-call-site seam that returns true — regression anchor', function () {
    $d = PlatformDescriptor::make('instagram')
        ->label('Instagram')
        ->category(PlatformCategory::Social)
        ->resource('App\\Http\\Resources\\Platforms\\InstagramConnectionResource');

    $user = new User(['id' => (string) Str::uuid()]);

    // If this fails after a predicate was introduced, wire all call sites first
    // (see PlatformDescriptor::availableFor() docblock for the checklist).
    expect($d->availableFor($user))->toBeTrue();
});

// requiresCapability() — the seam the 2026-07-15 sector gating wired for the
// reservation-family platforms (opentable/resdiary/nowbookit). The CAP-1
// regression anchor above proves a descriptor WITHOUT an opt-in predicate is
// unaffected; these prove the predicate itself, in isolation from any real
// AccountCapabilities/User setup.

it('requiresCapability makes availableFor consult the predicate', function () {
    $d = PlatformDescriptor::make('opentable')
        ->label('OpenTable')
        ->category(PlatformCategory::Reservations)
        ->requiresCapability(fn (User $user) => $user->display_name === 'Allowed');

    expect($d->availableFor(new User(['display_name' => 'Allowed'])))->toBeTrue();
    expect($d->availableFor(new User(['display_name' => 'Denied'])))->toBeFalse();
});

it('requiresCapability only affects the descriptor it is called on', function () {
    $gated = PlatformDescriptor::make('opentable')->requiresCapability(fn () => false);
    $ungated = PlatformDescriptor::make('resdiary');

    expect($gated->availableFor(new User))->toBeFalse();
    expect($ungated->availableFor(new User))->toBeTrue();
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
    expect($d->connectStrategy()->resolve('good')->selection)->toBe(['username' => 'good', 'url' => 'https://x.com/good']);
    expect($d->connectStrategy()->resolve('bad')->failed())->toBeTrue();
    expect($d->connectErrorMessage())->toBe('Enter your X handle or profile URL (x.com/yourname).');
});

it('returns null connect accessors before a strategy is attached', function () {
    $d = PlatformDescriptor::make('linkedin');

    expect($d->connectStrategy())->toBeNull();
    expect($d->connectErrorMessage())->toBeNull();
});

it('carries a payload class', function () {
    $d = PlatformDescriptor::make('spotify')
        ->payload(LinkPayload::class);

    expect($d->payloadClass())->toBe(LinkPayload::class);
});

it('defaults payloadClass and fetchStrategy to null', function () {
    $d = PlatformDescriptor::make('plain');

    expect($d->payloadClass())->toBeNull();
    expect($d->fetchStrategy())->toBeNull();
});

it('carries a fetch strategy', function () {
    $fetch = new class implements FetchStrategy
    {
        public function fetch(IntegrationConnection $connection): array
        {
            return ['fetched' => true];
        }
    };

    $d = PlatformDescriptor::make('youtube')->fetch($fetch);

    expect($d->fetchStrategy())->toBe($fetch);
});
