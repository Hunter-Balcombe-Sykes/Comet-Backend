<?php

// COV-LANE-3: DatabaseServiceProvider::boot() sets statement_timeout/lock_timeout
// on the pgsql connection, but bailed unconditionally whenever runningUnitTests()
// was true — true in every PHPUnit config, phpunit.pg.xml included, since it sets
// APP_ENV=testing and runs under the console SAPI. So the SET statements never
// ran in any test lane, and a regression that dropped or renamed them would pass
// silently everywhere.
//
// DB_APPLY_TIMEOUTS_IN_TESTS=1 (set only in the postgres-tests CI job env, see
// ci.yml) narrows that bail so this ONE lane, against a disposable postgres:16
// container, actually applies the SET statements. Every other lane — including a
// developer's own `composer test:pg` without the var set — keeps the original
// unconditional bail, so `config:clear` still can't force a real Supabase TCP
// connect in a DNS-less sandbox.
//
// The positive control below is not optional. Without it this test would pass
// identically whether DatabaseServiceProvider actually read config() or just
// hardcoded '30s'/'10s' into the SET statements — the exact vacuous-guard shape
// COV-GUARD spent a unit removing. Overriding config to a distinct value and
// re-running boot() proves the SET statement really reads the configured value.

use App\Providers\DatabaseServiceProvider;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    if (getenv('DB_APPLY_TIMEOUTS_IN_TESTS') !== '1') {
        $this->markTestSkipped('Requires DB_APPLY_TIMEOUTS_IN_TESTS=1 (set by the postgres-tests CI job).');
    }
});

it('applies the configured statement_timeout and lock_timeout to the pgsql connection', function () {
    $statementTimeout = DB::selectOne('SHOW statement_timeout')->statement_timeout;
    $lockTimeout = DB::selectOne('SHOW lock_timeout')->lock_timeout;

    expect($statementTimeout)->toBe('30s')
        ->and($lockTimeout)->toBe('10s');
});

it('positive control: a differently-configured timeout is actually applied, not a hardcoded default', function () {
    config(['database.connections.pgsql.statement_timeout' => 5000]);

    (new DatabaseServiceProvider($this->app))->boot();

    $statementTimeout = DB::selectOne('SHOW statement_timeout')->statement_timeout;

    expect($statementTimeout)->toBe('5s');
});
