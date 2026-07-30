<?php

use Tests\Authz\AuthzTestCase;
use Tests\Authz\Expectations;
use Tests\Authz\Matrix;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

uses(AuthzTestCase::class);

/**
 * Public endpoints must answer 404 for an unknown id, never 403.
 *
 * A 403 on a public surface confirms the resource exists, which turns the
 * endpoint into an enumeration oracle — the project rule in CLAUDE.md is
 * "Public endpoints: always 404 (403 enables enumeration)".
 *
 * Deliberately narrower than the other suites: it asserts only the absence of
 * 403, not a specific success status, because public routes legitimately return
 * a spread of codes (200 for a published site, 410 for SIGNUP_MOVED, 422 for a
 * malformed payload).
 */
it('answers 404 and never 403 for an unknown public id', function () {
    $expectations = Expectations::load();

    $cases = collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->group() === 'public')
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern(), $c->method))
        ->values();

    $failures = [];

    foreach ($cases as $case) {
        $uri = $case->uri;

        foreach (array_keys($case->params) as $param) {
            $uri = str_replace(['{'.$param.'}', '{'.$param.'?}'], 'authz-does-not-exist', $uri);
        }

        $status = Matrix::isolated(fn () => $this
            ->json($case->method, '/'.$uri, $expectations->bodyFor($case->pattern(), $case->method))
            ->getStatusCode());

        if ($status === 403) {
            $failures[] = sprintf(
                "AUTHZ %s\n  observed 403 on a PUBLIC route — this confirms the resource exists\n"
                ."  and enables enumeration. Return 404 instead.\n"
                .'  fix: the controller, not the expectations file',
                $case->key(),
            );
        }
    }

    if ($failures !== []) {
        $this->fail(Matrix::report($failures, $cases->count(), 'Public surface'));
    }

    expect($failures)->toBe([]);
});
