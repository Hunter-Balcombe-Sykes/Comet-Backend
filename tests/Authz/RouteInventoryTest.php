<?php

use Illuminate\Database\Eloquent\Model;
use Tests\Authz\AuthzTestCase;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

uses(AuthzTestCase::class);

it('enumerates only api routes and skips HEAD/OPTIONS', function () {
    $cases = RouteInventory::all();

    expect($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        expect($case->uri)->toStartWith('api/');
        expect($case->method)->not->toBeIn(['HEAD', 'OPTIONS']);
    }
});

it('resolves model-bound params to a real Eloquent FQCN', function () {
    $models = collect(RouteInventory::all())
        ->flatMap(fn (RouteCase $c) => array_values($c->params))
        ->filter()
        ->unique()
        ->values();

    // Measured 2026-07-30: 12 distinct models are reachable via reflection.
    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        expect(is_subclass_of($model, Model::class))->toBeTrue("{$model} is not an Eloquent model");
    }
});

it('reports unresolved params rather than dropping them', function () {
    // api/enquiries/{id}/read fetches by hand — the param is typed string, so
    // reflection cannot resolve it. It must surface as UNRESOLVED, never as
    // "no params", or the route silently leaves the matrix. This is the exact
    // regression the 2026-07-30 spike caught in the original design.
    //
    // Was pinned to `GET api/enquiries/{id}` until 2026-08-06; that route was
    // removed as dead-with-zero-callers in 1917be75b. Its siblings under
    // api/enquiries/{id}/* keep the identical hand-fetched string param, so the
    // property under test is unchanged — only the specimen moved.
    $case = collect(RouteInventory::all())
        ->first(fn (RouteCase $c) => $c->uri === 'api/enquiries/{id}/read' && $c->method === 'POST');

    expect($case)->not->toBeNull();
    expect($case->hasParams())->toBeTrue();
    expect($case->unresolvedParams())->toContain('id');
});

it('groups routes by surface', function () {
    $groups = collect(RouteInventory::all())
        ->map(fn (RouteCase $c) => $c->group())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($groups)->toBe(['platforms', 'public', 'staff', 'user']);
});
