<?php

// Audit / Supabase security-advisor regression guard: the 12 trigger and helper
// functions hardened in 20260606040000_pin_function_search_paths.sql, plus
// audit.prune_handle_change_log (PRIV-2, 20260718010000_handle_change_log_retention_prune.sql),
// must keep a PINNED search_path. A function with a mutable (null proconfig)
// search_path inherits the caller's search_path, letting a malicious caller
// shadow any unqualified object reference inside the body — a privilege-
// escalation / resolution-hijack vector. The advisor (`function_search_path_mutable`)
// flags any such function; this sentinel keeps it from regressing.
//
// THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE. It lived in tests/Feature and gated
// every assertion on a helper that asked whether the connection was Postgres.
// Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory SQLite
// unconditionally — deliberately, so BaseModel-forced models never dial the real
// Supabase host — so that helper returned false in every lane, and every
// assertion here skipped silently in CI and locally.
//
// Strategy mirrors tests/Schema/ModerationSchemaRlsTest.php and
// DesignKitsRlsTest.php: introspect pg_proc.proconfig (PostgreSQL-only) rather
// than exercising behaviour. It now runs in the applied-schema lane
// (phpunit.schema.xml / `composer test:schema`, see Tests\SchemaTestCase),
// against a container that the real supabase/migrations/ set has been applied
// to by scripts/db/apply-migrations.sh. The base case skips the whole lane
// when no migrated Postgres is present, so an unpinned search_path now fails
// instead of vanishing.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

/**
 * Fetch a function's proconfig (the array of `key=value` SET clauses) by
 * schema + name. Trigger functions are uniquely named within a schema, so name
 * is sufficient here; all 12 targets are unambiguous.
 *
 * @return object{proconfig: ?string}|null
 */
function fetchFunctionSearchPathConfig(string $schema, string $name): ?object
{
    return DB::selectOne(
        "SELECT array_to_string(p.proconfig, ',') AS proconfig
           FROM pg_proc p
           JOIN pg_namespace n ON n.oid = p.pronamespace
          WHERE n.nspname = ? AND p.proname = ?",
        [$schema, $name]
    );
}

// The 12 functions pinned in 20260606040000_pin_function_search_paths.sql, plus the
// three audit-retention prune functions — audit.prune_handle_change_log (PRIV-2,
// 20260718010000) and audit.prune_user_deletion_audit / audit.prune_data_export_audit
// (B8 PRIV-2/PRIV-3, 20260722010000). All are SECURITY DEFINER, so an unpinned
// search_path is a privilege-escalation vector, not just a resolution-hijack one:
// every identifier in their bodies is fully schema-qualified, so the empty path is safe.
// Each is [schema, name].
$searchPathFunctions = [
    ['public', 'set_updated_at'],
    ['core', 'set_updated_at'],
    ['core', 'set_media_variants_updated_at'],
    ['core', 'reject_staff_audit_log_mutation'],
    ['core', 'trg_handle_change_log_append_only'],
    ['core', 'trg_user_handle_alias_check'],
    ['core', 'trg_user_handle_change'],
    ['site', 'compute_user_url'],
    ['site', 'trg_recompute_partna_url'],
    ['site', 'create_empty_design_kit'],
    ['site', 'trg_sites_url_sync'],
    ['audit', 'prune_handle_change_log'],
    ['audit', 'prune_user_deletion_audit'],
    ['audit', 'prune_data_export_audit'],
    ['audit', 'null_user_audit_links'],
];

dataset('search_path_functions', array_map(
    fn (array $f) => ["{$f[0]}.{$f[1]}", $f[0], $f[1]],
    $searchPathFunctions
));

// Every hardened function must exist and carry a non-null proconfig that pins
// search_path — otherwise the function is mutable and the advisor re-fires.
it('keeps a pinned search_path on every hardened function', function (string $label, string $schema, string $name) {
    $row = fetchFunctionSearchPathConfig($schema, $name);

    expect($row)->not->toBeNull("Function {$label} not found in pg_proc.");
    expect($row->proconfig)->not->toBeNull(
        "{$label} has a NULL proconfig — its search_path is mutable (function_search_path_mutable). Pin it via ALTER FUNCTION ... SET search_path."
    );
    expect($row->proconfig)->toContain('search_path');
})->with('search_path_functions');
