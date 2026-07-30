<?php

namespace Tests\Authz;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared mechanics for the four matrix suites.
 *
 * WHY NOT PEST DATASETS. A dataset() closure is evaluated at PHPUnit's
 * data-provider resolution time, which happens BEFORE the application boots —
 * `app('router')` there throws "Target class [router] does not exist". Every
 * suite therefore runs as ONE test that loops the route table and aggregates
 * failures. That also produces better triage output: one message listing every
 * offending route beats a hundred separate failures scrolling past.
 *
 * WHY SAVEPOINTS. Aggregating into one test means all cases share the enclosing
 * per-test transaction, so a DELETE that wrongly SUCCEEDS would destroy identity
 * B's row and poison every later case in the same run. Each case is therefore
 * fired inside a nested transaction — Laravel emits a SAVEPOINT — and rolled
 * back to it afterwards. That also recovers the connection when a request
 * leaves the transaction aborted (SQLSTATE 25P02), which a flat try/catch
 * cannot do on Postgres.
 */
final class Matrix
{
    /**
     * Substitute identity B's row ids into a route pattern.
     *
     * @param  string|null  $fallbackId  used for params with no known fixture; when null, an
     *                                   unresolvable param is an error instead
     * @return array{0: string|null, 1: string|null} [uri, error]
     */
    public static function resolveUri(
        RouteCase $case,
        Expectations $expectations,
        ?string $fallbackId = null,
    ): array {
        $uri = $case->uri;

        foreach ($case->params as $param => $model) {
            $model ??= $expectations->fixtureFor($case->pattern(), $param);

            $id = $model !== null ? Fixtures::idFor($model) : null;

            if ($id === null && $model !== null && $fallbackId === null) {
                return [null, sprintf(
                    "AUTHZ %s\n  no seeded identity-B row for %s\n"
                    .'  fix: add one to tests/Authz/Fixtures::seedOwnedBy()',
                    $case->key(),
                    $model,
                )];
            }

            if ($id === null && $model === null) {
                if ($fallbackId === null) {
                    return [null, sprintf(
                        "AUTHZ %s\n"
                        ."  param `%s` could not be resolved to a model by reflection and has no\n"
                        ."  `fixture:` mapping. Read the controller to see which model it fetches, then add:\n"
                        ."    - route: \"%s\"\n"
                        ."      fixture: { %s: <Model FQCN> }\n"
                        ."  ...or exempt it with a written reason.\n"
                        .'  fix: tests/Authz/expectations.yaml',
                        $case->key(),
                        $param,
                        $case->pattern(),
                        $param,
                    )];
                }

                $id = $fallbackId;
            }

            $uri = str_replace(['{'.$param.'}', '{'.$param.'?}'], $id ?? $fallbackId, $uri);
        }

        return ['/'.$uri, null];
    }

    /**
     * Run one probe inside a savepoint, always rolling back to it.
     *
     * @template T
     *
     * @param  callable(): T  $probe
     * @return T
     */
    public static function isolated(callable $probe): mixed
    {
        $connection = DB::connection('pgsql');
        $depth = $connection->transactionLevel();

        $connection->beginTransaction();

        try {
            return $probe();
        } finally {
            try {
                if ($connection->transactionLevel() > $depth) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                // A rollback that itself fails means the connection is beyond
                // recovery for this case; the enclosing per-test rollback in
                // AuthzTestCase still cleans up.
            }
        }
    }

    /**
     * Format an aggregated failure list for a suite.
     *
     * @param  array<int, string>  $failures
     */
    public static function report(array $failures, int $total, string $suite): string
    {
        return sprintf(
            "%s: %d of %d route case(s) failed.\n\n%s\n",
            $suite,
            count($failures),
            $total,
            implode("\n\n", $failures),
        );
    }
}
