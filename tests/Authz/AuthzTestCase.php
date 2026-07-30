<?php

namespace Tests\Authz;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base case for the AUTHORIZATION lane (phpunit.authz.xml / `composer
 * test:authz`; see tests/Authz/README.md).
 *
 * Extends the framework TestCase directly, like SchemaTestCase and
 * PostgresTestCase: inheriting Tests\TestCase would reintroduce the
 * unconditional SQLite redirect in its setUp(), and this lane's entire premise
 * is a real Postgres carrying the real schema — 175 routes fired against
 * hand-built stand-in tables would 500 on "no such table" rather than 404.
 *
 * Each test runs inside a transaction that is rolled back in tearDown. Cases in
 * this lane deliberately send DELETE and PATCH cross-tenant; when authorization
 * is broken those requests SUCCEED, and without the rollback one real finding
 * would destroy the fixtures every later assertion depends on and cascade into
 * dozens of false ones. Fixtures themselves are seeded and COMMITTED once per
 * process (see Fixtures::ensureSeeded) so they survive that rollback.
 */
abstract class AuthzTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
            $this->unavailable(
                'Authz lane requires DB_CONNECTION=pgsql (see phpunit.authz.xml / composer test:authz).'
            );
        }

        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $e) {
            $this->unavailable('Authz lane requires a reachable Postgres server: '.$e->getMessage());
        }

        $applied = DB::connection('pgsql')->selectOne(
            "SELECT to_regclass('core.users') IS NOT NULL AS ok"
        );

        if (! $applied || ! $applied->ok) {
            $this->unavailable(
                'Authz lane requires the supabase/migrations set to be applied first '
                .'(scripts/db/apply-migrations.sh against a throwaway container).'
            );
        }

        DB::connection('pgsql')->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Preconditions missing: skip locally, FAIL where the lane is declared
     * required (AUTHZ_LANE_REQUIRED=1, set by the CI job).
     *
     * Same reasoning as SchemaTestCase::unavailable — a lane that stands down
     * quietly when its database is not set up reports green while testing
     * nothing, which is the exact failure that lane was created to end.
     */
    private function unavailable(string $why): void
    {
        if (getenv('AUTHZ_LANE_REQUIRED') === '1') {
            $this->fail($why.' (AUTHZ_LANE_REQUIRED=1 — refusing to pass by skipping.)');
        }

        $this->markTestSkipped($why);
    }
}
