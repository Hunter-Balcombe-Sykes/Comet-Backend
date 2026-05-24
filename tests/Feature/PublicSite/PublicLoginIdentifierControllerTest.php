<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupProfessionalsTable();
});

it('echoes a valid email back lowercased', function () {
    $response = $this->postJson('/api/public/auth/resolve-identifier', [
        'identifier' => 'Tobias@Example.com',
    ]);

    $response->assertOk()->assertJson(['email' => 'tobias@example.com']);
});

it('resolves a handle to the matching primary email', function () {
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-000000000010',
        'auth_user_id' => 'auth-user-handle-resolve',
        'handle' => 'tobias-balcombe-ehrlich',
        'handle_lc' => 'tobias-balcombe-ehrlich',
        'display_name' => 'Tobias',
        'primary_email' => 'tobias@example.com',
        'account_type' => 'individual',
        'status' => 'active',
        'onboarding_step' => 0,
    ]);

    $response = $this->postJson('/api/public/auth/resolve-identifier', [
        'identifier' => 'Tobias-Balcombe-Ehrlich',
    ]);

    $response->assertOk()->assertJson(['email' => 'tobias@example.com']);
});

it('returns null for an unknown handle', function () {
    $response = $this->postJson('/api/public/auth/resolve-identifier', [
        'identifier' => 'nobody-here',
    ]);

    $response->assertOk()->assertJson(['email' => null]);
});

it('rejects requests without an identifier', function () {
    $response = $this->postJson('/api/public/auth/resolve-identifier', []);

    $response->assertStatus(422);
});
