<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base case for the APPLIED-SCHEMA lane (phpunit.schema.xml / `composer
 * test:schema`; see tests/Schema/).
 *
 * Distinct from {@see PostgresTestCase} in what the database is expected to
 * contain. That lane's tests self-provision the handful of tables they need and
 * drop them again, because what they exercise is Postgres BEHAVIOUR — trigger
 * semantics, transaction-abort states — which SQLite cannot reproduce. This
 * lane exercises the SCHEMA ITSELF: it asks pg_constraint whether the CHECK
 * constraints and FKs that supabase/migrations/ is supposed to create are
 * actually there and actually validated. That question is only meaningful
 * against a database those migrations have really been applied to, so this lane
 * is run after scripts/db/apply-migrations.sh, not against a bare container.
 *
 * WHY THIS LANE HAD TO EXIST. CheckConstraintsTest lived in tests/Feature and
 * asserted it was the safety net for un-applied migrations. It was not running
 * anywhere. Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory
 * SQLite UNCONDITIONALLY — that is deliberate and load-bearing for the rest of
 * the suite, but it meant the file's own is-this-Postgres guard was false in
 * every lane, so all 22 of its assertions skipped silently, in CI and locally,
 * for as long as they existed. A safety net that never runs reads exactly like
 * a safety net that always passes.
 *
 * Extends the framework TestCase directly for the same reason PostgresTestCase
 * does: inheriting Tests\TestCase would reintroduce the SQLite redirect that
 * disabled these tests in the first place.
 */
abstract class SchemaTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
            $this->unavailable(
                'Schema lane requires DB_CONNECTION=pgsql (see phpunit.schema.xml / composer test:schema).'
            );
        }

        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $e) {
            $this->unavailable('Schema lane requires a reachable Postgres server: '.$e->getMessage());
        }

        // The migrations must actually have been applied. Checked explicitly so
        // a run against an empty database reports "you did not apply the
        // migrations" rather than 22 individually-missing constraints, which
        // would read as catastrophic schema drift.
        $applied = DB::connection('pgsql')->selectOne(
            "SELECT to_regclass('core.users') IS NOT NULL AS ok"
        );

        if (! $applied || ! $applied->ok) {
            $this->unavailable(
                'Schema lane requires the supabase/migrations set to be applied first '
                .'(scripts/db/apply-migrations.sh).'
            );
        }
    }

    /**
     * Lane preconditions are missing: skip locally, FAIL where the lane is
     * declared required (SCHEMA_LANE_REQUIRED=1, set by the CI job).
     *
     * This distinction is the entire point of the lane's existence. These tests
     * spent their whole life skipping silently and reporting green, and a lane
     * that skips when its database is not set up has exactly the same shape one
     * level up: break the apply step in CI and all 22 tests would quietly stand
     * down while the build stayed green — the identical false safety net, just
     * better hidden. So CI is told the preconditions are mandatory, and a
     * failure to meet them is a red build rather than a quiet one.
     *
     * A developer without a migrated container still gets a clean skip.
     */
    private function unavailable(string $why): void
    {
        if (getenv('SCHEMA_LANE_REQUIRED') === '1') {
            $this->fail($why.' (SCHEMA_LANE_REQUIRED=1 — refusing to pass by skipping.)');
        }

        $this->markTestSkipped($why);
    }
}
