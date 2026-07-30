<?php

use Tests\Authz\AuthzTestCase;
use Tests\Authz\Expectations;
use Tests\Authz\Fixtures;
use Tests\Authz\Matrix;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

uses(AuthzTestCase::class);

/**
 * Staff routes fired with (a) a plain user's token and (b) staff-shaped claims
 * at aal1. Both must be rejected.
 *
 * Unlike the cross-tenant matrix this covers routes with NO params too — a
 * staff route that leaks to a normal user leaks whether or not it takes an id.
 * Unresolvable params get a syntactically valid uuid that matches nothing;
 * reaching the authorization layer is what matters, not what the id points at.
 *
 * Measured 2026-07-30: 104 staff routes, 81 of them param-bearing.
 */

/** @return array<int, RouteCase> */
function authzStaffCases(Expectations $expectations): array
{
    return collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->group() === 'staff')
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->values()
        ->all();
}

it('refuses a plain user token on staff routes', function () {
    Fixtures::ensureSeeded();
    $expectations = Expectations::load();
    $cases = authzStaffCases($expectations);

    $failures = [];

    foreach ($cases as $case) {
        [$uri] = Matrix::resolveUri($case, $expectations, Matrix::UNKNOWN_UUID);

        $status = Matrix::isolated(fn () => actingAsUser(Fixtures::identityA())
            ->json($case->method, $uri, $expectations->bodyFor($case->pattern()))
            ->getStatusCode());

        if (! in_array($status, [401, 403, 404], true)) {
            $failures[] = sprintf(
                "AUTHZ %s\n  expected: 401/403/404 for a NON-STAFF caller\n  observed: %d\n"
                .'  fix:      the controller or its middleware — a staff route reachable by a '
                .'normal user is a privilege-escalation finding, not a triage entry',
                $case->key(),
                $status,
            );
        }
    }

    if ($failures !== []) {
        $this->fail(Matrix::report($failures, count($cases), 'Staff boundary (plain user)'));
    }

    expect($failures)->toBe([]);
});

it('refuses staff claims at aal1 on staff routes', function () {
    Fixtures::ensureSeeded();
    $expectations = Expectations::load();
    $cases = authzStaffCases($expectations);

    $failures = [];

    foreach ($cases as $case) {
        [$uri] = Matrix::resolveUri($case, $expectations, Matrix::UNKNOWN_UUID);

        // Staff identity is a core.partna_staff row, but AAL is a JWT claim: a
        // staff member who has not completed MFA carries aal1. require.aal2
        // must reject regardless of the staff row.
        $status = Matrix::isolated(fn () => actingAsUser(Fixtures::identityA(), ['aal' => 'aal1', 'amr' => []])
            ->json($case->method, $uri, $expectations->bodyFor($case->pattern()))
            ->getStatusCode());

        if (! in_array($status, [401, 403, 404], true)) {
            $failures[] = sprintf(
                "AUTHZ %s\n  expected: 401/403/404 for an aal1 caller\n  observed: %d\n"
                .'  fix:      the require.aal2 middleware on this route',
                $case->key(),
                $status,
            );
        }
    }

    if ($failures !== []) {
        $this->fail(Matrix::report($failures, count($cases), 'Staff boundary (aal1)'));
    }

    expect($failures)->toBe([]);
});
