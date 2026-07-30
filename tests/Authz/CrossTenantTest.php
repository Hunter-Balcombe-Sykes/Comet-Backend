<?php

use Tests\Authz\AuthzTestCase;
use Tests\Authz\Expectations;
use Tests\Authz\Fixtures;
use Tests\Authz\Matrix;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;
use Tests\Authz\Verdict;

uses(AuthzTestCase::class);

/**
 * Every param-bearing user/platforms route, fired with identity A's token and
 * identity B's row ids. A response that is not 404 is a finding.
 *
 * Runs as one aggregating test rather than a dataset — see Matrix's docblock
 * for why (data providers resolve before the app boots).
 */
it('refuses identity A access to identity B resources', function () {
    Fixtures::ensureSeeded();
    $expectations = Expectations::load();

    $cases = collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => in_array($c->group(), ['user', 'platforms'], true))
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->values();

    $failures = [];

    foreach ($cases as $case) {
        [$uri, $error] = Matrix::resolveUri($case, $expectations);

        if ($error !== null) {
            $failures[] = $error;

            continue;
        }

        $status = Matrix::isolated(fn () => actingAsUser(Fixtures::identityA())
            ->json($case->method, $uri, $expectations->bodyFor($case->pattern()))
            ->getStatusCode());

        if (($message = Verdict::describe($status, $case)) !== null) {
            $failures[] = $message;
        }
    }

    if ($failures !== []) {
        $this->fail(Matrix::report($failures, $cases->count(), 'Cross-tenant matrix'));
    }

    expect($failures)->toBe([]);
});
