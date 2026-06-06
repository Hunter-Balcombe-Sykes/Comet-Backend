<?php

// Audit #P2-04 regression guard: staff writes to core.users and site.customers
// must be gated to ADMIN staff only. A bare `EXISTS partna_staff WHERE
// auth_user_id = auth.uid()` (no role filter) on a write policy would let a
// rogue `support` JWT mutate any user/customer row.
//
// Strategy mirrors tests/Feature/Database/CheckConstraintsTest.php: introspect
// pg_policies (PostgreSQL-only) rather than attempting to exercise RLS, which
// SQLite cannot enforce. Skips on the default SQLite test driver.
//
// To run against a Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... phpunit --filter AdminOnlyWritePoliciesTest

use Illuminate\Support\Facades\DB;

function adminWritePoliciesIsPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

/**
 * Fetch the USING (qual) + WITH CHECK expressions for a named policy.
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
    if (! adminWritePoliciesIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

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
    if (! adminWritePoliciesIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = fetchPolicy($schema, $table, $policy);
    expect($row)->not->toBeNull("Expected policy [{$schema}.{$table}.{$policy}] to exist.");

    // The branch that actually authorises writes is the one Postgres consults
    // for that command: WITH CHECK for INSERT, USING for DELETE, both for UPDATE.
    $expr = trim(($row->qual ?? '').' '.($row->with_check ?? ''));

    expect($expr)->toContain('partna_staff', "[{$policy}] should reference partna_staff.");
    expect($expr)->toContain(
        "role = 'admin'",
        "[{$policy}] staff branch is NOT gated on role = 'admin' — support staff can write."
    );
})->with('admin_gated_write_policies');

// The SELECT policies must remain open to ANY staff (no admin gate) so support
// can still read. A regression that copies the admin gate here would break
// the staff dashboard read path.
dataset('any_staff_select_policies', [
    'users select' => ['core', 'users', 'users_select_authenticated'],
    'customers select' => ['site', 'customers', 'customers_select_authenticated'],
]);

it('keeps SELECT open to any staff (no admin gate)', function (string $schema, string $table, string $policy) {
    if (! adminWritePoliciesIsPostgres()) {
        $this->markTestSkipped('pg_policies introspection requires PostgreSQL.');
    }

    $row = fetchPolicy($schema, $table, $policy);
    expect($row)->not->toBeNull("Expected SELECT policy [{$schema}.{$table}.{$policy}] to exist.");
    expect($row->cmd)->toBe('SELECT');
    expect($row->qual)->toContain('partna_staff', "[{$policy}] should let staff read.");
    expect($row->qual)->not->toContain(
        "role = 'admin'",
        "[{$policy}] must NOT gate reads on admin — support staff need read access."
    );
})->with('any_staff_select_policies');
