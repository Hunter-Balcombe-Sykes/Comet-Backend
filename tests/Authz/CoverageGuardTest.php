<?php

use Tests\Authz\AuthzTestCase;
use Tests\Authz\Expectations;
use Tests\Authz\Fixtures;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

uses(AuthzTestCase::class);

/**
 * Completeness, not correctness. The matrix proves routes behave; this proves
 * no route escaped the matrix. A new route added to routes/api/*.php lands here
 * first, and cannot be silently omitted.
 */
it('has a fixture mapping or a written exemption for every param-bearing route', function () {
    $expectations = Expectations::load();

    $unclassified = collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->filter(function (RouteCase $c) use ($expectations) {
            foreach ($c->unresolvedParams() as $param) {
                if ($expectations->fixtureFor($c->pattern(), $param) === null) {
                    return true;
                }
            }

            return false;
        })
        ->map(fn (RouteCase $c) => $c->pattern())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($unclassified)->toBe([], sprintf(
        "%d route pattern(s) are neither mapped nor exempted.\n\n%s\n\n"
        ."Each needs ONE of:\n"
        ."  - route: \"<pattern>\"\n"
        ."    fixture: { <param>: <Model FQCN> }   # which of identity B's rows to substitute\n"
        ."  - route: \"<pattern>\"\n"
        ."    expect: exempt\n"
        ."    reason: \"<what the param actually identifies, and why it is not tenant-scoped>\"\n\n"
        .'fix: tests/Authz/expectations.yaml',
        count($unclassified),
        implode("\n", array_map(fn ($k) => '  '.$k, $unclassified)),
    ));
});

it('has no stale entries naming routes that no longer exist', function () {
    $patterns = collect(RouteInventory::all())->map(fn (RouteCase $c) => $c->pattern())->unique();

    $stale = collect(Expectations::load()->patterns())
        ->reject(fn (string $p) => $patterns->contains($p))
        ->values()
        ->all();

    expect($stale)->toBe([], sprintf(
        "expectations.yaml names %d route(s) that no longer exist:\n%s\n\n"
        .'Delete them — a stale exemption excuses nothing and hides that the route moved. '
        .'fix: tests/Authz/expectations.yaml',
        count($stale),
        implode("\n", array_map(fn ($p) => '  '.$p, $stale)),
    ));
});

it('has a seeded fixture row for every model the matrix substitutes', function () {
    $expectations = Expectations::load();
    $seeded = Fixtures::seededModels();

    $missing = collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->flatMap(function (RouteCase $c) use ($expectations) {
            $models = [];

            foreach ($c->params as $param => $model) {
                $model ??= $expectations->fixtureFor($c->pattern(), $param);

                if ($model !== null) {
                    $models[] = ltrim($model, '\\');
                }
            }

            return $models;
        })
        ->unique()
        ->reject(fn (string $m) => in_array($m, $seeded, true))
        ->sort()
        ->values()
        ->all();

    expect($missing)->toBe([], sprintf(
        "No seeded identity-B row for %d model(s):\n%s\n\n"
        .'fix: add them to tests/Authz/Fixtures::seedOwnedBy()',
        count($missing),
        implode("\n", array_map(fn ($m) => '  '.$m, $missing)),
    ));
});
