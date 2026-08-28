<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

// Base test case for the real-Postgres regression lane (phpunit.pg.xml /
// `composer test:pg`; see tests/Postgres/).
//
// Deliberately does NOT extend Tests\TestCase — its setUp() unconditionally
// repoints the 'pgsql' connection at in-memory SQLite (so BaseModel-forced
// Eloquent queries never dial the real Supabase host), which is exactly the
// behaviour these tests need to NOT have: they exist to exercise Postgres
// transaction-abort semantics (SQLSTATE 25P02) that SQLite cannot reproduce.
// Extends the framework's TestCase directly instead, whose createApplication()
// already boots bootstrap/app.php with no extra trait required.
//
// No RefreshDatabase / DatabaseTransactions — tests in this lane provision
// (and drop) their own tables per-file/per-test and manage their own
// transactions deliberately (that's the thing under test), so wrapping the
// test body in an extra transaction would defeat the point.
abstract class PostgresTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // QUEUE_CONNECTION=sync in this lane (phpunit.pg.xml) means any real
        // dispatch runs inline. Since T4 (BuildState::bump(), 2026-08-27),
        // every content write reachable through ProjectionWriter::bumpSite()/
        // invalidateSiteLanes() dispatches BuildSiteDocumentJob, which needs
        // site.pages/site.sections/site.site_documents — tables this lane's
        // hand-rolled per-file DDL was never asked to provision, because the
        // document-build pipeline is not what these tests are about. Faked
        // globally so no write anywhere in this lane can accidentally run it
        // for real; a test that specifically wants queue assertions (e.g.
        // EnquiryReconcileSkipLockedTest) still calls Queue::fake() itself,
        // which is idempotent against this one.
        Queue::fake();

        // No pgsql connection configured at all (e.g. DB_CONNECTION left at
        // its sqlite default) — skip rather than blow up with a driver error.
        if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
            $this->unavailable(
                'Postgres lane requires DB_CONNECTION=pgsql (see phpunit.pg.xml / composer test:pg).'
            );
        }

        // Driver says pgsql but the server may not actually be reachable
        // (container not running, wrong host/port) — skip cleanly instead of
        // erroring so this lane degrades gracefully without a live Postgres.
        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $e) {
            $this->unavailable('Postgres lane requires a reachable Postgres server: '.$e->getMessage());
        }

        // Guard: this lane CREATEs and DROPs production-named tables in core.*/site.*.
        // Local .env here points DB_CONNECTION=pgsql at a real dev host, and
        // phpunit.pg.xml deliberately does not override DB_* (see its own docblock)
        // — so an unguarded `composer test:pg` run locally would aim these
        // destructive statements at whatever database .env happens to name. Only
        // ever run this lane against a throwaway database — the CI service
        // container (DB_DATABASE=partna_test) or an explicitly opted-in local one.
        $db = DB::connection('pgsql')->selectOne('select current_database() as db')->db;
        if ($db !== 'partna_test' && getenv('PG_LANE_DISPOSABLE') !== '1') {
            $this->unavailable("Refusing to provision core.*/site.* on '{$db}' — set PG_LANE_DISPOSABLE=1 to override.");
        }
    }

    /**
     * Lane preconditions are missing: skip locally, FAIL where the lane is
     * declared required (PG_LANE_REQUIRED=1, set by the CI job).
     *
     * Mirrors SchemaTestCase::unavailable() — same bug shape one layer up: a
     * failed/unreachable postgres:16 service container made this lane skip
     * every assertion and report green having run zero of them. See
     * tests/SchemaTestCase.php for the full rationale.
     *
     * A developer without a local Postgres still gets a clean skip.
     */
    private function unavailable(string $why): void
    {
        if (getenv('PG_LANE_REQUIRED') === '1') {
            $this->fail($why.' (PG_LANE_REQUIRED=1 — refusing to pass by skipping.)');
        }

        $this->markTestSkipped($why);
    }
}
