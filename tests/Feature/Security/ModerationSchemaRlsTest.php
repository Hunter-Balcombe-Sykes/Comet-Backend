<?php

// Audit #P3-16 regression guard: the five moderation.* tables must keep Row
// Level Security ENABLED and FORCED, with a staff-only SELECT policy and an
// app_backend FOR ALL policy on each. These tables hold reporter PII, case
// types, evidence payloads, and decision rationale. RLS is defence-in-depth:
// `moderation` is not in api.schemas and anon/authenticated have no USAGE on
// the schema today, but if either is ever granted without RLS, every case row
// would become world-readable to any authenticated JWT. Added in
// 20260606030000_moderation_schema_rls.sql.
//
// Strategy mirrors tests/Feature/Security/DesignKitsRlsTest.php and
// AdminOnlyWritePoliciesTest.php: introspect pg_class/pg_policies
// (PostgreSQL-only) rather than exercising RLS, which SQLite cannot enforce.
// Skips on the default SQLite test driver.
//
// To run against a Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... phpunit --filter ModerationSchemaRlsTest

use Illuminate\Support\Facades\DB;

function moderationRlsIsPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

/**
 * Fetch a named policy on a moderation table with its roles + expressions.
 *
 * @return object{cmd: string, roles: ?string, qual: ?string, with_check: ?string}|null
 */
function fetchModerationPolicy(string $table, string $policy): ?object
{
    return DB::selectOne(
        "SELECT cmd, array_to_string(roles, ',') AS roles, qual, with_check
           FROM pg_policies
          WHERE schemaname = 'moderation' AND tablename = ? AND policyname = ?",
        [$table, $policy]
    );
}

// The five tables created in 20260528000000_create_moderation_schema.sql
// (csam_quarantine + ncmec_submissions were later dropped). action_log is keyed
// off decisions; the rest hold case/reporter/evidence data directly.
$moderationTables = ['cases', 'case_signals', 'evidence', 'decisions', 'action_log'];

dataset('moderation_tables', $moderationTables);

// RLS must be ENABLED and FORCED on every moderation table — without it, a
// future api.schemas / USAGE grant would expose reporter PII to any JWT.
it('keeps RLS enabled and forced on every moderation table', function (string $table) {
    if (! moderationRlsIsPostgres()) {
        $this->markTestSkipped('pg_class introspection requires PostgreSQL.');
    }

    // Cast to int: PDO_pgsql can return booleans as 't'/'f' strings, and the
    // string 'f' is truthy in PHP — so compare the integer form explicitly.
    $row = DB::selectOne(
        "SELECT (c.relrowsecurity)::int AS rls_enabled,
                (c.relforcerowsecurity)::int AS rls_forced
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'moderation' AND c.relname = ?",
        [$table]
    );

    expect($row)->not->toBeNull("moderation.{$table} table not found.");
    expect((int) $row->rls_enabled)->toBe(1, "RLS is NOT enabled on moderation.{$table} — case data is unprotected if the schema is ever exposed.");
    expect((int) $row->rls_forced)->toBe(1, "FORCE ROW LEVEL SECURITY is not set on moderation.{$table}.");
})->with('moderation_tables');

// Each table must carry exactly the two expected policies: an app_backend FOR
// ALL policy and a staff-only SELECT policy.
it('defines the staff-only SELECT policy on every moderation table', function (string $table) {
    if (! moderationRlsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = fetchModerationPolicy($table, "{$table}_staff_select");
    expect($row)->not->toBeNull("Expected staff SELECT policy on moderation.{$table}.");
    expect($row->cmd)->toBe('SELECT', "[{$table}_staff_select] must be a SELECT policy.");
    expect($row->roles)->toContain('authenticated', "[{$table}_staff_select] must apply to the authenticated role.");

    // The staff gate: membership in partna_staff with an admin/support role.
    // Critically it must NOT be open to all authenticated users.
    expect($row->qual)->toContain('partna_staff', "[{$table}_staff_select] must gate on partna_staff membership.");
    expect($row->qual)->toContain('auth.uid()', "[{$table}_staff_select] must bind the staff check to the caller via auth.uid().");
})->with('moderation_tables');

it('defines the app_backend FOR ALL policy on every moderation table', function (string $table) {
    if (! moderationRlsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = fetchModerationPolicy($table, "{$table}_app_backend_all");
    expect($row)->not->toBeNull("Expected app_backend FOR ALL policy on moderation.{$table}.");
    // pg_policies reports FOR ALL as cmd = 'ALL'.
    expect($row->cmd)->toBe('ALL', "[{$table}_app_backend_all] must be a FOR ALL policy.");
    expect($row->roles)->toContain('app_backend', "[{$table}_app_backend_all] must apply to the app_backend role.");
})->with('moderation_tables');

// No moderation policy may be open to anon, or to authenticated without a staff
// gate — these tables must never be reachable by an end user. This is the exact
// exposure #P3-16 hardens against.
it('never exposes moderation data to anon or non-staff authenticated callers', function (string $table) {
    if (! moderationRlsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $policies = DB::select(
        "SELECT policyname, array_to_string(roles, ',') AS roles, qual
           FROM pg_policies
          WHERE schemaname = 'moderation' AND tablename = ?",
        [$table]
    );

    foreach ($policies as $p) {
        expect($p->roles)->not->toContain('anon', "[{$table}.{$p->policyname}] must NOT grant anon access to moderation data.");

        // Any policy granting the authenticated role must gate on staff
        // membership — a bare authenticated policy would leak case data.
        if (str_contains((string) $p->roles, 'authenticated')) {
            expect($p->qual)->toContain('partna_staff', "[{$table}.{$p->policyname}] grants authenticated access without a partna_staff gate.");
        }
    }
})->with('moderation_tables');
