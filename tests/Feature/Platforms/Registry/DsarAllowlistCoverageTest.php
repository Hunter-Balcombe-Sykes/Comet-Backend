<?php

// #PRIV-2: every REGISTERED platform must have a DsarPayloadFilter allowlist
// entry (or an explicit `[]`, like `shop`). Missing one means filter()'s
// fail-closed branch fires — the platform's payload renders EMPTY in every
// data-subject export and reports MissingDsarAllowlistException to Nightwatch
// on every export request touching that platform.
//
// Deliberately NOT a hardcoded exemption list — same reasoning as
// PublicAllowlistCoverageTest (TEST-3): runs the REAL filter over every live
// registry key and lets its own behaviour decide, then proves the guard can
// actually fail with a deliberately-unregistered key.

use App\Exceptions\Platforms\MissingDsarAllowlistException;
use App\Services\Platforms\DsarPayloadFilter;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Exceptions;

it('never reports MissingDsarAllowlistException for a currently-registered platform', function () {
    $fake = Exceptions::fake();

    foreach (app(PlatformRegistry::class)->keys() as $key) {
        // Probe payload must be a non-empty ARRAY — filter() returns [] on a
        // non-array payload before the allowlist lookup, which would make
        // every platform pass vacuously.
        DsarPayloadFilter::filter($key, ['__probe' => 'x']);
    }

    $missing = collect($fake->reported())
        ->filter(fn ($e) => $e instanceof MissingDsarAllowlistException)
        ->map(fn (MissingDsarAllowlistException $e) => $e->platform)
        ->values()
        ->all();

    expect($missing)->toBe([], 'Registered platform(s) have no DsarPayloadFilter allowlist entry — '
        .'their payload renders EMPTY in every data-subject export and reports '
        .'MissingDsarAllowlistException to Nightwatch. Add an entry to '
        .'DsarPayloadFilter::DSAR_ALLOWLIST, or `[]` if the platform stores nothing '
        .'exportable in its connection payload (as `shop` does): '
        .implode(', ', $missing));
});

// Mutation-proof: proves the guard can actually detect a missing entry by
// feeding it a platform key that is deliberately never registered.
it('proves the guard can fail: an unregistered platform key IS reported', function () {
    $fake = Exceptions::fake();

    DsarPayloadFilter::filter('totally-unregistered', ['__probe' => 'x']);

    $reportedPlatforms = collect($fake->reported())
        ->filter(fn ($e) => $e instanceof MissingDsarAllowlistException)
        ->map(fn (MissingDsarAllowlistException $e) => $e->platform)
        ->values()
        ->all();

    expect($reportedPlatforms)->toBe(['totally-unregistered']);
});

it('every DsarPayloadFilter allowlist entry resolves to a currently-registered platform', function () {
    // The inverse check — a stale entry for a retired platform key is
    // harmless but worth catching so the const doesn't accumulate dead keys.
    $registered = app(PlatformRegistry::class)->keys();

    $reflection = new ReflectionClass(DsarPayloadFilter::class);
    $allowlist = $reflection->getConstant('DSAR_ALLOWLIST');

    $stale = array_values(array_diff(array_keys($allowlist), $registered));

    expect($stale)->toBe([], "DsarPayloadFilter::DSAR_ALLOWLIST has entries for platforms no longer registered:\n  - ".implode("\n  - ", $stale));
});
