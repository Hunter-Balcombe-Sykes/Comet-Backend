<?php

use App\Http\Controllers\Api\User\Customers\UserCustomerController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P3-29: The deprecated `pagination` shim (mirror of `meta`) has been removed.
 * Assert that:
 *   - the canonical `meta` key is present with correct pagination fields
 *   - the removed `pagination` key is absent
 */
beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.customers (
        id TEXT PRIMARY KEY,
        user_id TEXT,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        marketing_opt_in_cached INTEGER,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('customer list response contains canonical meta key with pagination fields', function () {
    $pro = createAffiliateTenant('cust-pagination-a');

    $req = tenantRequestAs($pro);
    $response = app(UserCustomerController::class)->index($req);
    $payload = $response->getData(true);

    // `meta` must be present and contain the standard pagination fields.
    expect($payload)->toHaveKey('meta');
    expect($payload['meta'])->toHaveKeys([
        'current_page',
        'per_page',
        'total',
        'last_page',
        'next_page_url',
        'prev_page_url',
    ]);
});

it('customer list response does NOT contain the deprecated pagination key', function () {
    $pro = createAffiliateTenant('cust-pagination-b');

    $req = tenantRequestAs($pro);
    $response = app(UserCustomerController::class)->index($req);
    $payload = $response->getData(true);

    // The `pagination` shim (deprecated mirror of `meta`) must be gone.
    expect($payload)->not->toHaveKey('pagination');
});

it('customer list response meta reflects actual total count', function () {
    $pro = createAffiliateTenant('cust-pagination-c');
    $now = now()->toDateTimeString();

    DB::table('site.customers')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $pro->id, 'email' => 'c1@example.test', 'full_name' => 'C One', 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'user_id' => $pro->id, 'email' => 'c2@example.test', 'full_name' => 'C Two', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $req = tenantRequestAs($pro);
    $response = app(UserCustomerController::class)->index($req);
    $payload = $response->getData(true);

    expect($payload['meta']['total'])->toBe(2);
    expect($payload['meta']['current_page'])->toBe(1);
});
