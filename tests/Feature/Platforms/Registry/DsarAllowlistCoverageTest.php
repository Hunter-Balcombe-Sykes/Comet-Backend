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

// #PRIV-2, the other half: the coverage tests above prove every platform HAS an
// allowlist. These two prove the allowlists EXCLUDE the right things.
//
// THIRD_PARTY_KEYS documents the derivation rule ("copy the public allowlist,
// then remove these") but nothing executes it — the removal was done by hand
// when each entry was authored. That makes the rule true today and unenforced
// tomorrow: nothing stops a new platform entry from copying `reviews` straight
// in. These tests are what turn the constant from a comment into a guard, which
// is why it is read here directly rather than via reflection.
it('no DSAR allowlist entry contains a third-party key', function () {
    $reflection = new ReflectionClass(DsarPayloadFilter::class);
    $allowlist = $reflection->getConstant('DSAR_ALLOWLIST');

    $leaks = [];
    foreach ($allowlist as $platform => $keys) {
        $hit = array_values(array_intersect($keys, DsarPayloadFilter::THIRD_PARTY_KEYS));
        if ($hit !== []) {
            $leaks[$platform] = $hit;
        }
    }

    expect($leaks)->toBe([], 'DSAR allowlist(s) carry personal data about a THIRD PARTY (a reviewer, an '
        ."event organiser, a venue) into a data-subject export:\n  - "
        .implode("\n  - ", array_map(
            fn ($p, $k) => "{$p}: ".implode(', ', $k),
            array_keys($leaks),
            $leaks
        )));
});

// The behavioural proof, not just the shape one: a stored payload carrying every
// third-party key must come out of filter() without them.
it('strips third-party keys from a real export payload', function () {
    $filtered = DsarPayloadFilter::filter('google-business', [
        'name' => 'Some Salon',
        'rating' => 4.7,
        'reviewCount' => 128,
        'reviews' => [['author' => 'Jane Doe', 'authorPhoto' => 'https://…', 'text' => 'Great cut']],
        'reviewSummary' => ['text' => ['text' => 'Customers praise the staff']],
    ]);

    expect($filtered)->not->toHaveKey('reviews')
        ->and($filtered)->not->toHaveKey('reviewSummary')
        // The account holder's OWN data survives — this is an Article 15 export,
        // so over-stripping is a failure too.
        ->and($filtered)->toHaveKey('name')
        ->and($filtered['rating'])->toBe(4.7)
        ->and($filtered['reviewCount'])->toBe(128);

    $event = DsarPayloadFilter::filter('eventbrite', [
        'name' => 'Launch Night',
        'location' => 'Melbourne',
        'organiser' => ['name' => 'Someone Else', 'url' => 'https://…'],
        'venue' => ['name' => 'A Venue', 'address' => '1 Example St'],
    ]);

    expect($event)->not->toHaveKey('organiser')
        ->and($event)->not->toHaveKey('venue')
        ->and($event)->toHaveKey('location');
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
