<?php

use Tests\Authz\AuthzTestCase;
use Tests\Authz\RouteCase;
use Tests\Authz\Verdict;

uses(AuthzTestCase::class);

// Uniquely named: unnamespaced Pest files share one global symbol table, so a
// second file declaring authzVerdictCase() would fatal the whole run.
function authzVerdictCase(): RouteCase
{
    return new RouteCase('PATCH', 'api/site/sections/{section}', 'X@update', ['section' => null]);
}

it('accepts a 404', function () {
    expect(Verdict::describe(404, authzVerdictCase()))->toBeNull();
});

it('rejects a 200 as cross-tenant access', function () {
    expect(Verdict::describe(200, authzVerdictCase()))->toContain('cross-tenant');
});

it('rejects a 403 as an enumeration leak', function () {
    expect(Verdict::describe(403, authzVerdictCase()))->toContain('403');
});

it('rejects a 422 as inconclusive', function () {
    expect(Verdict::describe(422, authzVerdictCase()))->toContain('inconclusive');
});

it('rejects a 500', function () {
    expect(Verdict::describe(500, authzVerdictCase()))->toContain('500');
});

it('names the route and the file to edit in every failure', function () {
    foreach ([200, 403, 422, 500] as $status) {
        $message = Verdict::describe($status, authzVerdictCase());

        expect($message)->toContain('PATCH api/site/sections/{section}');
        expect($message)->toContain('tests/Authz/expectations.yaml');
    }
});
