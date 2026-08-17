<?php

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Registry\DerivedDescriptorFactory;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    // Capabilities memoize per User instance in a WeakMap; a stale entry from a
    // sibling test would silently invert an availability assertion.
    AccountCapabilities::flushCache();
});

// Defined and used in THIS file only — a cross-file Pest helper breaks the
// parallel runner in this repo.
function derivedGateUser(string $handle, string $accountType, ?string $sector): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

it('derives a descriptor for a connectable url-detected brand', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();

    // menulog is the worked example: a real ordering brand with a catalog
    // surface, a URL detector and no hand-written descriptor.
    expect($derived)->toHaveKey('menulog');
    expect($derived['menulog']->routeShape())->toBe(PlatformRouteShape::Brand);
    expect($derived['menulog']->isDerived())->toBeTrue();
    expect($derived['menulog']->connectField())->toBe('url');
});

it('keys every derived slug by legacyFor, so it matches the generated platform column', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();

    foreach ($derived as $slug => $descriptor) {
        expect($descriptor->key())->toBe($slug);
        // legacyFor() is idempotent on a bare slug — the derived key must already
        // be in that reduced form, or the generated column would never match it.
        expect(LegacyPlatformMap::legacyFor($slug))->toBe($slug);
    }
});

it('never derives a slug a hand-written descriptor already owns', function () {
    $derived = array_keys(app(DerivedDescriptorFactory::class)->build());

    expect(array_values(array_intersect($derived, PlatformRegistry::handWrittenFreeze())))->toBe([]);
});

it('skips surfaces that are not connectable', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();

    // partna.* are pseudo-surfaces and generic/direct are the no-detector
    // fallbacks; none may ever become a connectable platform.
    foreach (['custom', 'booking', 'reservations', 'online-ordering', 'shop', 'generic', 'direct'] as $slug) {
        expect($derived)->not->toHaveKey($slug);
    }
});

it('assigns the routing-class capability predicate', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();
    $surfaces = CompiledCatalog::surfaces();

    $checked = 0;
    foreach ($surfaces as $key => $surface) {
        $slug = LegacyPlatformMap::legacyFor($key);
        if (! isset($derived[$slug])) {
            continue;
        }

        $expected = DerivedDescriptorFactory::CAPABILITY_BY_ROUTING_CLASS[$surface['routing_class']] ?? null;
        if ($expected === null) {
            continue;
        }

        // A capability-bound brand must not be available to an account that
        // lacks the capability. business+barber has booking, not ordering;
        // business+restaurant has ordering, not booking.
        $barber = derivedGateUser('dd-barber-'.substr(md5($slug), 0, 6), 'business', 'barber');
        $chef = derivedGateUser('dd-food-'.substr(md5($slug), 0, 6), 'business', 'restaurant');

        $allowed = $expected === 'can_use_booking' ? $barber : $chef;
        $denied = $expected === 'can_use_booking' ? $chef : $barber;

        expect($derived[$slug]->availableFor($allowed))->toBeTrue("{$slug} should be available under {$expected}");
        expect($derived[$slug]->availableFor($denied))->toBeFalse("{$slug} should be gated by {$expected}");
        $checked++;
    }

    // Guard the guard: a zero-iteration loop would pass vacuously.
    expect($checked)->toBeGreaterThan(5);
});

it('leaves social and content brands ungated', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();
    $user = derivedGateUser('dd-plain', 'partna', null);

    // stan.store is routing_class shop — no capability applies.
    if (isset($derived['stan'])) {
        expect($derived['stan']->availableFor($user))->toBeTrue();
    }
    expect($derived)->not->toBeEmpty();
});
