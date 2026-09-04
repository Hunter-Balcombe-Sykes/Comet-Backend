<?php

// statement_timeout / lock_timeout now live on the app_backend ROLE (migration
// 20260905120000), not on a per-connection SET.
//
// The old DatabaseServiceProvider called DB::connection()->getPdo() in boot() to
// issue those SETs, which opened a socket in EVERY process that booted the
// framework — FPM children, queue workers, artisan commands, each scheduler tick —
// whether or not it ever ran a query. In Supavisor SESSION mode each of those
// pinned a pool slot for the life of the process. Measured on dev 2026-09-04:
// 17 of 23 pooled connections (74%) had executed nothing but those two SETs.
// The provider is gone and connections are lazy again.
//
// What this test proves: the migration's mechanism — that ALTER ROLE ... SET
// lands both settings in pg_roles.rolconfig, which Postgres then applies at
// backend startup.
//
// What it CANNOT prove here: that a login as app_backend inherits them. Role
// defaults apply at login, and this lane connects as the container superuser;
// `SET ROLE` does not re-run them. That half was verified against dev on
// 2026-09-04 — an in-app probe on dev-api reported statement_timeout=30s and
// lock_timeout=10s from a real app connection.

use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $exists = DB::selectOne("select 1 as ok from pg_roles where rolname = 'app_backend'");

    if (! $exists) {
        $this->markTestSkipped('No app_backend role in this container; the role defaults have nothing to attach to.');
    }
});

it('lands both timeout defaults on the app_backend role', function () {
    // The migration's own statements, verbatim, so this fails if they drift.
    DB::statement("ALTER ROLE app_backend SET statement_timeout = '30s'");
    DB::statement("ALTER ROLE app_backend SET lock_timeout = '10s'");

    $config = DB::selectOne("select rolconfig::text as cfg from pg_roles where rolname = 'app_backend'")->cfg;

    expect($config)->toContain('statement_timeout=30s')
        ->and($config)->toContain('lock_timeout=10s');
});

it('positive control: rolconfig reflects the value actually written, not a fixed string', function () {
    DB::statement("ALTER ROLE app_backend SET statement_timeout = '5s'");

    $config = DB::selectOne("select rolconfig::text as cfg from pg_roles where rolname = 'app_backend'")->cfg;

    expect($config)->toContain('statement_timeout=5s');

    // Leave the role as the migration would have it.
    DB::statement("ALTER ROLE app_backend SET statement_timeout = '30s'");
});
