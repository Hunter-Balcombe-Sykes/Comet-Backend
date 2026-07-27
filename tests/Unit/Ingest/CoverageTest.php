<?php

// Tier-S runtime property tests for App\Ingest\Landing\Coverage (plan §4/§22,
// C5): the domination matrix that is the entire basis for ever deleting
// anything. Pure PHP value objects — no database, no Laravel bootstrap.

use App\Ingest\Landing\Coverage;

it('exhaustive coverage dominates everything, regardless of key or order value', function () {
    $coverage = Coverage::exhaustive();

    expect($coverage->dominates('any-key', null))->toBeTrue();
    expect($coverage->dominates('any-key', 42))->toBeTrue();
    expect($coverage->dominates('any-key', 'zzz'))->toBeTrue();
});

it('unknown coverage dominates nothing, regardless of key or order value', function () {
    $coverage = Coverage::unknown();

    expect($coverage->dominates('any-key', null))->toBeFalse();
    expect($coverage->dominates('any-key', 42))->toBeFalse();
    expect($coverage->dominates('any-key', 'zzz'))->toBeFalse();
});

it('prefix coverage dominates numeric order values at or after "from", but not before it', function () {
    $coverage = Coverage::prefix(10, 5);

    expect($coverage->dominates('k', 10))->toBeTrue();  // exactly at the boundary
    expect($coverage->dominates('k', 15))->toBeTrue();  // after
    expect($coverage->dominates('k', 9))->toBeFalse();  // before
});

it('prefix coverage dominates string order values at or after "from", but not before it', function () {
    $coverage = Coverage::prefix('2026-01-15', 5);

    expect($coverage->dominates('k', '2026-01-15'))->toBeTrue(); // exactly at the boundary
    expect($coverage->dominates('k', '2026-06-01'))->toBeTrue(); // after
    expect($coverage->dominates('k', '2025-12-01'))->toBeFalse(); // before
});

it('prefix coverage never dominates a null order value', function () {
    $coverage = Coverage::prefix(10, 5);

    expect($coverage->dominates('k', null))->toBeFalse();
});

it('window coverage dominates order values inside [from, to] only', function () {
    $coverage = Coverage::window('2026-01-01', '2026-01-31');

    expect($coverage->dominates('k', '2026-01-15'))->toBeTrue();  // inside
    expect($coverage->dominates('k', '2026-01-01'))->toBeTrue();  // inclusive lower bound
    expect($coverage->dominates('k', '2026-01-31'))->toBeTrue();  // inclusive upper bound
    expect($coverage->dominates('k', '2025-12-31'))->toBeFalse(); // before the window
    expect($coverage->dominates('k', '2026-02-01'))->toBeFalse(); // after the window
});

it('window coverage never dominates a null order value', function () {
    $coverage = Coverage::window('2026-01-01', '2026-01-31');

    expect($coverage->dominates('k', null))->toBeFalse();
});

it('every coverage kind round-trips through toArray/fromArray unchanged', function () {
    $cases = [
        Coverage::exhaustive(),
        Coverage::unknown(),
        Coverage::prefix(10, 5),
        Coverage::window('2026-01-01', '2026-01-31'),
    ];

    foreach ($cases as $coverage) {
        $rebuilt = Coverage::fromArray($coverage->toArray());
        expect($rebuilt->toArray())->toBe($coverage->toArray());
    }
});
