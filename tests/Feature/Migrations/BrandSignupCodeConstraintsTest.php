<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Pgsql-only: the CHECK and UNIQUE constraints are Postgres-specific migration
// semantics that SQLite does not enforce. Tests are skipped on the SQLite runner.

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Constraint tests require a real Postgres connection.');
    }
})->group('brand-signup-code-constraints');

function insertBrandProfileRow(string $code): string
{
    $proId = (string) Str::uuid();
    DB::statement(
        "INSERT INTO core.professionals (id, auth_user_id, handle, handle_lc, display_name, primary_email, professional_type, status, onboarding_step)
         VALUES (?, ?, ?, ?, ?, ?, 'brand', 'active', 0)",
        [$proId, 'uid-'.Str::random(6), 'brand'.Str::random(4), 'brand'.Str::random(4), 'Brand', Str::random(6).'@example.com']
    );

    $id = (string) Str::uuid();
    DB::statement(
        'INSERT INTO brand.brand_profiles (id, professional_id, setup_complete, signup_code, signup_code_active)
         VALUES (?, ?, false, ?, true)',
        [$id, $proId, $code]
    );

    return $id;
}

it('duplicate signup_code insert fails UNIQUE constraint', function () {
    $code = bin2hex(random_bytes(8));

    insertBrandProfileRow($code);

    expect(fn () => insertBrandProfileRow($code))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('audit event outside the allowed set fails the CHECK constraint', function () {
    $brandProfileId = insertBrandProfileRow(bin2hex(random_bytes(8)));

    expect(fn () => DB::statement(
        "INSERT INTO brand.signup_code_audit (id, brand_profile_id, event, actor_type, created_at)
         VALUES (?, ?, 'invalid_event', 'system', now())",
        [(string) Str::uuid(), $brandProfileId]
    ))->toThrow(\Illuminate\Database\QueryException::class);
});

it('audit claimed event with NULL joined_professional_id fails compound CHECK constraint', function () {
    $brandProfileId = insertBrandProfileRow(bin2hex(random_bytes(8)));

    expect(fn () => DB::statement(
        "INSERT INTO brand.signup_code_audit (id, brand_profile_id, event, actor_type, joined_professional_id, created_at)
         VALUES (?, ?, 'claimed', 'public', NULL, now())",
        [(string) Str::uuid(), $brandProfileId]
    ))->toThrow(\Illuminate\Database\QueryException::class);
});

it('audit claimed event with a valid joined_professional_id succeeds', function () {
    $brandProfileId = insertBrandProfileRow(bin2hex(random_bytes(8)));

    $joinedProId = (string) Str::uuid();
    DB::statement(
        "INSERT INTO core.professionals (id, auth_user_id, handle, handle_lc, display_name, primary_email, professional_type, status, onboarding_step)
         VALUES (?, ?, ?, ?, ?, ?, 'professional', 'active', 0)",
        [$joinedProId, 'uid-'.Str::random(6), 'p'.Str::random(4), 'p'.Str::random(4), 'Partner', Str::random(6).'@example.com']
    );

    $result = DB::statement(
        "INSERT INTO brand.signup_code_audit (id, brand_profile_id, event, actor_type, joined_professional_id, created_at)
         VALUES (?, ?, 'claimed', 'public', ?, now())",
        [(string) Str::uuid(), $brandProfileId, $joinedProId]
    );

    expect($result)->toBeTrue();
});
