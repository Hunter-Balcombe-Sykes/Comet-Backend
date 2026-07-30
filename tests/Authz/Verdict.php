<?php

namespace Tests\Authz;

/**
 * Scores one cross-tenant response.
 *
 * Failure messages are part of the design, not polish. A developer meets this
 * lane for the first time as a red build on an unrelated PR; a message that
 * says only "route not covered" gets resolved by adding a blanket exemption.
 * Every message names the route, the observed status, what was expected, and
 * the file to edit.
 */
final class Verdict
{
    /** @return string|null null when the response is acceptable */
    public static function describe(int $status, RouteCase $case, int $expected = 404): ?string
    {
        if ($status === $expected) {
            return null;
        }

        $why = match (true) {
            $status >= 200 && $status < 300 => 'cross-tenant access — identity A read or modified identity B\'s row',
            $status === 403 => '403 leaks existence; project rule is 404 for resources that do not belong to the '
                .'caller (403 is reserved for role/type restrictions)',
            $status === 422 => 'inconclusive — validation rejected the request before authorization ran, so the '
                .'authorization question was never asked. Add a `body:` to the expectations entry so the request '
                .'reaches the policy',
            $status === 401 => 'unexpected 401 — the test identity was not authenticated; this is a harness fault, '
                .'not a finding',
            $status >= 500 => $status.' — application or harness defect; check the exception before triaging',
            default => 'unexpected status '.$status,
        };

        return sprintf(
            "AUTHZ %s\n  expected: %d\n  observed: %d\n  reason:   %s\n  fix:      tests/Authz/expectations.yaml",
            $case->key(),
            $expected,
            $status,
            $why,
        );
    }
}
