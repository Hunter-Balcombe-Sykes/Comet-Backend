<?php

// Regression guard for SCHEMA-1 + SCHEMA-2: site.platform_connections must have
// FORCE ROW LEVEL SECURITY (it had ENABLE but not FORCE after
// 20260609000000_harden_platform_connections.sql), and the five site.menu_* tables
// must have both ENABLE and FORCE plus an app_backend FOR ALL policy.
//
// Without FORCE the table owner can bypass all policies; without RLS entirely, any
// future PostgREST exposure would let authenticated JWTs read all users' menus.
//
// Strategy: introspect pg_class (relrowsecurity / relforcerowsecurity) and
// pg_policies — PostgreSQL-only. Skips on the default SQLite test driver.
// To run against a Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... phpunit --filter PlatformAndMenuRlsTest

use Illuminate\Support\Facades\DB;

// Tables that must have RLS ENABLED + FORCED + the _app_backend_all policy.
// platform_connections already has ENABLE + the policy; this guard adds FORCE.
dataset('rls_tables', [
    'platform_connections' => ['site', 'platform_connections', 'platform_connections_app_backend_all'],
    'menus'                => ['site', 'menus',                'menus_app_backend_all'],
    'menu_categories'      => ['site', 'menu_categories',      'menu_categories_app_backend_all'],
    'menu_items'           => ['site', 'menu_items',           'menu_items_app_backend_all'],
    'menu_platform_links'  => ['site', 'menu_platform_links',  'menu_platform_links_app_backend_all'],
    'menu_item_platforms'  => ['site', 'menu_item_platforms',  'menu_item_platforms_app_backend_all'],
]);

it('has RLS enabled and forced on each table', function (string $schema, string $table, string $_policy) {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pg_class introspection requires PostgreSQL.');
    }

    // Cast to int: PDO_pgsql can return booleans as 't'/'f' strings, and 'f' is
    // truthy in PHP — compare the integer form explicitly.
    $row = DB::selectOne(
        "SELECT (c.relrowsecurity)::int    AS rls_enabled,
                (c.relforcerowsecurity)::int AS rls_forced
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = ? AND c.relname = ?",
        [$schema, $table]
    );

    expect($row)->not->toBeNull("{$schema}.{$table} table not found in pg_class.");
    expect((int) $row->rls_enabled)->toBe(1, "RLS is NOT enabled on {$schema}.{$table}.");
    expect((int) $row->rls_forced)->toBe(1, "FORCE ROW LEVEL SECURITY is not set on {$schema}.{$table}.");
})->with('rls_tables');

it('has the app_backend FOR ALL policy on each table', function (string $schema, string $table, string $policy) {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT cmd,
                array_to_string(roles, ',') AS roles,
                qual,
                with_check
           FROM pg_policies
          WHERE schemaname = ? AND tablename = ? AND policyname = ?",
        [$schema, $table, $policy]
    );

    expect($row)->not->toBeNull("Policy [{$policy}] not found on {$schema}.{$table}.");
    expect($row->cmd)->toBe('ALL', "[{$policy}] must be a FOR ALL policy.");
    expect($row->roles)->toContain('app_backend', "[{$policy}] must grant to the app_backend role.");
    expect(trim((string) ($row->qual ?? '')))->toBe('true', "[{$policy}] USING clause must be unconditional (true).");
    expect(trim((string) ($row->with_check ?? '')))->toBe('true', "[{$policy}] WITH CHECK clause must be unconditional (true).");
})->with('rls_tables');
