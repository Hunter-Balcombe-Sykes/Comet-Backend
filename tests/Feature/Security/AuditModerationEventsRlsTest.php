<?php

// Regression guard for audit.moderation_events, the last table in the `audit`
// schema to get Row Level Security (added 20260724123223). It is the append-only
// log written by App\Services\Moderation\ModerationAuditService via the
// AuditEvent model, and holds staff actor ids + moderation action payloads.
//
// Policy shape deliberately differs from the site.* tables guarded in
// PlatformAndMenuRlsTest: the audit schema is append-only and app_backend holds
// only SELECT + INSERT on this table, so it gets a split insert/select pair
// rather than a FOR ALL policy — mirroring audit.staff_audit_log. A FOR ALL
// policy here would describe UPDATE/DELETE paths the grants themselves deny.
//
// Strategy mirrors ModerationSchemaRlsTest / PlatformAndMenuRlsTest: introspect
// pg_class + pg_policies (PostgreSQL-only) rather than exercising RLS, which
// SQLite cannot enforce. Skips on the default SQLite test driver.
// To run against a Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... phpunit --filter AuditModerationEventsRlsTest

use Illuminate\Support\Facades\DB;

function auditModerationEventsIsPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

it('keeps RLS enabled and forced on audit.moderation_events', function () {
    if (! auditModerationEventsIsPostgres()) {
        $this->markTestSkipped('pg_class introspection requires PostgreSQL.');
    }

    // Cast to int: PDO_pgsql can return booleans as 't'/'f' strings, and the
    // string 'f' is truthy in PHP — compare the integer form explicitly.
    $row = DB::selectOne(
        "SELECT (c.relrowsecurity)::int      AS rls_enabled,
                (c.relforcerowsecurity)::int AS rls_forced
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'audit' AND c.relname = 'moderation_events'"
    );

    expect($row)->not->toBeNull('audit.moderation_events not found in pg_class.');
    expect((int) $row->rls_enabled)->toBe(1, 'RLS is NOT enabled on audit.moderation_events.');
    expect((int) $row->rls_forced)->toBe(1, 'FORCE ROW LEVEL SECURITY is not set on audit.moderation_events.');
});

// The append-only pair: app_backend may INSERT and SELECT, nothing else.
dataset('moderation_events_app_backend_policies', [
    'insert' => ['moderation_events_app_backend_insert', 'INSERT'],
    'select' => ['moderation_events_app_backend_select', 'SELECT'],
]);

it('has the append-only app_backend policies', function (string $policy, string $cmd) {
    if (! auditModerationEventsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT cmd, array_to_string(roles, ',') AS roles, qual, with_check
           FROM pg_policies
          WHERE schemaname = 'audit' AND tablename = 'moderation_events' AND policyname = ?",
        [$policy]
    );

    expect($row)->not->toBeNull("Policy [{$policy}] not found on audit.moderation_events.");
    expect($row->cmd)->toBe($cmd, "[{$policy}] must be a FOR {$cmd} policy.");
    expect($row->roles)->toContain('app_backend', "[{$policy}] must grant to the app_backend role.");
})->with('moderation_events_app_backend_policies');

// No UPDATE/DELETE policy may ever appear — the audit schema is append-only.
it('never grants an UPDATE or DELETE path on audit.moderation_events', function () {
    if (! auditModerationEventsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $rows = DB::select(
        "SELECT policyname, cmd
           FROM pg_policies
          WHERE schemaname = 'audit' AND tablename = 'moderation_events'
            AND cmd IN ('UPDATE', 'DELETE', 'ALL')"
    );

    expect($rows)->toBe([], 'audit.moderation_events must stay append-only — no UPDATE/DELETE/ALL policy is permitted.');
});

// Staff read path must stay role-gated; anon must never appear on any policy.
it('gates the staff SELECT policy to admin or support staff only', function () {
    if (! auditModerationEventsIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT cmd, array_to_string(roles, ',') AS roles, qual
           FROM pg_policies
          WHERE schemaname = 'audit' AND tablename = 'moderation_events'
            AND policyname = 'moderation_events_staff_select'"
    );

    expect($row)->not->toBeNull('Policy [moderation_events_staff_select] not found.');
    expect($row->cmd)->toBe('SELECT', 'The staff policy must be SELECT-only.');
    expect($row->roles)->toContain('authenticated');
    expect($row->roles)->not->toContain('anon', 'anon must never be granted a moderation audit read path.');
    expect($row->qual)->toContain('partna_staff', 'The staff SELECT policy must gate on core.partna_staff membership.');
    expect($row->qual)->toContain('admin', 'The staff SELECT policy must restrict to the admin/support roles.');
    expect($row->qual)->toContain('support', 'The staff SELECT policy must restrict to the admin/support roles.');
});
