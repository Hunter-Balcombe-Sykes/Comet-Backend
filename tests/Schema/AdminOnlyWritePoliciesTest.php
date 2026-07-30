<?php

// Audit #P2-04 regression guard: staff writes to core.users and site.customers
// must be gated to ADMIN staff only. A bare `EXISTS partna_staff WHERE
// auth_user_id = auth.uid()` (no role filter) on a write policy would let a
// rogue `support` JWT mutate any user/customer row.
//
// THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE. It lived in tests/Feature and gated
// every assertion on a helper that asked whether the connection was Postgres.
// Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory SQLite
// unconditionally — deliberately, so BaseModel-forced models never dial the real
// Supabase host — so that helper returned false in every lane, and every
// assertion here skipped silently in CI and locally.
//
// It now runs in the applied-schema lane (phpunit.schema.xml / `composer
// test:schema`, see Tests\SchemaTestCase), against a container that the real
// supabase/migrations/ set has been applied to by scripts/db/apply-migrations.sh.
// The per-test skip guard is gone: the base case skips the whole lane when no
// migrated Postgres is present, so a re-widened staff-write policy now fails
// instead of vanishing.
//
// The `role = 'admin'` assertions below depend on pg_get_expr's rendering: an
// unreserved identifier like `role` is left bare by quote_identifier, and the
// literal is cast, so the qual text is `cs.role = 'admin'::text` — the
// substring match works because of that specific rendering, not by accident.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

/**
 * Fetch the USING (qual) + WITH CHECK expressions for a named policy.
 *
 * NOTE: `fetchPolicy` is a dangerously generic name for a Pest file-scope
 * function — Pest test files share a GLOBAL symbol table, and a redeclaration
 * in another tests/Schema/ file is a hard fatal under --parallel. Do not
 * redeclare this name elsewhere in the lane.
 *
 * @return object{cmd: string, qual: ?string, with_check: ?string}|null
 */
function fetchPolicy(string $schema, string $table, string $policy): ?object
{
    return DB::selectOne(
        'SELECT cmd, qual, with_check
           FROM pg_policies
          WHERE schemaname = ? AND tablename = ? AND policyname = ?',
        [$schema, $table, $policy]
    );
}

// The old FOR ALL policies must be gone — their presence means the split never ran.
it('drops the unsplit FOR ALL staff-write policies', function () {
    expect(fetchPolicy('core', 'users', 'users_all_authenticated'))->toBeNull(
        'Legacy core.users FOR ALL policy still exists — admin-write split did not apply.'
    );
    expect(fetchPolicy('site', 'customers', 'customers_all_authenticated'))->toBeNull(
        'Legacy site.customers FOR ALL policy still exists — admin-write split did not apply.'
    );
});

// Each write policy's staff branch must carry the admin gate.
dataset('admin_gated_write_policies', [
    'users insert' => ['core', 'users', 'users_insert_owner_or_admin'],
    'users update' => ['core', 'users', 'users_update_owner_or_admin'],
    'users delete' => ['core', 'users', 'users_delete_owner_or_admin'],
    'customers insert' => ['site', 'customers', 'customers_insert_owner_or_admin'],
    'customers update' => ['site', 'customers', 'customers_update_owner_or_admin'],
    'customers delete' => ['site', 'customers', 'customers_delete_owner_or_admin'],
]);

it('gates every staff-write policy on role = admin', function (string $schema, string $table, string $policy) {
    $row = fetchPolicy($schema, $table, $policy);
    expect($row)->not->toBeNull("Expected policy [{$schema}.{$table}.{$policy}] to exist.");

    // The branch that actually authorises writes is the one Postgres consults
    // for that command: WITH CHECK for INSERT, USING for DELETE, both for UPDATE.
    $expr = trim(($row->qual ?? '').' '.($row->with_check ?? ''));

    expect($expr)->toContain('partna_staff');
    expect($expr)->toContain("role = 'admin'");
})->with('admin_gated_write_policies');

// The SELECT policies must remain open to ANY staff (no admin gate) so support
// can still read. A regression that copies the admin gate here would break
// the staff dashboard read path.
dataset('any_staff_select_policies', [
    'users select' => ['core', 'users', 'users_select_authenticated'],
    'customers select' => ['site', 'customers', 'customers_select_authenticated'],
]);

it('keeps SELECT open to any staff (no admin gate)', function (string $schema, string $table, string $policy) {
    $row = fetchPolicy($schema, $table, $policy);
    expect($row)->not->toBeNull("Expected SELECT policy [{$schema}.{$table}.{$policy}] to exist.");
    expect($row->cmd)->toBe('SELECT');
    expect($row->qual)->toContain('partna_staff');
    expect($row->qual)->not->toContain("role = 'admin'");
})->with('any_staff_select_policies');
