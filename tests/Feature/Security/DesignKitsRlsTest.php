<?php

// Audit #P1-14 regression guard: site.design_kits must keep Row Level Security
// enabled so a rogue authenticated JWT cannot GET /rest/v1/design_kits and read
// every user's design tokens. RLS + the owner/staff/anon policies were added in
// 20260602000000_design_kits_rls.sql (mirroring site.sites). This test prevents
// a regression that drops RLS or weakens the policy set on this PII-adjacent
// (per-user styling) table.
//
// Strategy mirrors tests/Feature/Security/AdminOnlyWritePoliciesTest.php:
// introspect pg_class/pg_policies (PostgreSQL-only) rather than exercising RLS,
// which SQLite cannot enforce. Skips on the default SQLite test driver.
//
// To run against a Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... phpunit --filter DesignKitsRlsTest

use Illuminate\Support\Facades\DB;

function designKitsRlsIsPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

/**
 * Fetch a named policy on site.design_kits with its roles + expressions.
 *
 * @return object{cmd: string, roles: ?string, qual: ?string, with_check: ?string}|null
 */
function fetchDesignKitsPolicy(string $policy): ?object
{
    return DB::selectOne(
        "SELECT cmd, array_to_string(roles, ',') AS roles, qual, with_check
           FROM pg_policies
          WHERE schemaname = 'site' AND tablename = 'design_kits' AND policyname = ?",
        [$policy]
    );
}

// RLS must be ENABLED and FORCED — design_kits was the lone site.* table without
// it, leaving every user's design tokens world-readable to any authenticated JWT.
it('keeps RLS enabled and forced on site.design_kits', function () {
    if (! designKitsRlsIsPostgres()) {
        $this->markTestSkipped('pg_class introspection requires PostgreSQL.');
    }

    // Cast to int: PDO_pgsql can return booleans as 't'/'f' strings, and the
    // string 'f' is truthy in PHP — so compare the integer form explicitly.
    $row = DB::selectOne(
        "SELECT (c.relrowsecurity)::int AS rls_enabled,
                (c.relforcerowsecurity)::int AS rls_forced
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'site' AND c.relname = 'design_kits'"
    );

    expect($row)->not->toBeNull('site.design_kits table not found.');
    expect((int) $row->rls_enabled)->toBe(1, 'RLS is NOT enabled on site.design_kits — any authenticated JWT can read all design tokens.');
    expect((int) $row->rls_forced)->toBe(1, 'FORCE ROW LEVEL SECURITY is not set on site.design_kits.');
});

// All five policies must exist with the expected command (mirrors site.sites).
dataset('design_kit_policies', [
    'select authenticated' => ['design_kits_select_authenticated', 'SELECT'],
    'anon published read' => ['design_kits_public_read_published', 'SELECT'],
    'insert authenticated' => ['design_kits_insert_authenticated', 'INSERT'],
    'update authenticated' => ['design_kits_update_authenticated', 'UPDATE'],
    'delete authenticated' => ['design_kits_delete_authenticated', 'DELETE'],
]);

it('defines the expected design_kits RLS policy for each command', function (string $policy, string $cmd) {
    if (! designKitsRlsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = fetchDesignKitsPolicy($policy);
    expect($row)->not->toBeNull("Expected policy [site.design_kits.{$policy}] to exist.");
    expect($row->cmd)->toBe($cmd, "[{$policy}] should be a {$cmd} policy.");
})->with('design_kit_policies');

// The anon policy is the public profile read path — it must be restricted to
// PUBLISHED sites so an unpublished site's design tokens are never exposed.
it('restricts the anon read policy to published sites', function () {
    if (! designKitsRlsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = fetchDesignKitsPolicy('design_kits_public_read_published');
    expect($row)->not->toBeNull('Anon public-read policy is missing.');
    expect($row->roles)->toContain('anon', 'Public read policy must apply to the anon role.');
    expect($row->qual)->toContain('is_published', 'Anon read policy must be gated on the parent site being published.');
});

// The authenticated policies must scope to the site owner (sites -> users on
// auth_user_id) or staff — never blanket-open to all authenticated users (the
// exact vulnerability #P1-14 closed).
dataset('design_kit_owner_scoped_policies', [
    'select authenticated' => ['design_kits_select_authenticated'],
    'insert authenticated' => ['design_kits_insert_authenticated'],
    'update authenticated' => ['design_kits_update_authenticated'],
    'delete authenticated' => ['design_kits_delete_authenticated'],
]);

it('scopes authenticated design_kits policies to the site owner or staff', function (string $policy) {
    if (! designKitsRlsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = fetchDesignKitsPolicy($policy);
    expect($row)->not->toBeNull("Expected policy [site.design_kits.{$policy}] to exist.");

    $expr = trim(($row->qual ?? '').' '.($row->with_check ?? ''));
    expect($expr)->toContain('auth_user_id', "[{$policy}] must scope to the owner via auth_user_id.");
    expect($expr)->toContain('partna_staff', "[{$policy}] must allow staff access.");
})->with('design_kit_owner_scoped_policies');
