<?php

use App\Http\Controllers\Api\User\Customers\UserCustomerController;
use App\Http\Requests\Api\User\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\User\Customer\UpdateCustomerRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupCustomersTable();
});

it('allows the owner to view their own customer', function () {
    $owner = createTenant('pro-view-owner');
    $customer = createCustomerFor($owner);
    $req = tenantRequestAs($owner);

    // No AuthorizationException thrown means the policy permitted the action.
    $response = app(UserCustomerController::class)->show($req, $customer);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from viewing another tenants customer with 404', function () {
    $owner = createTenant('pro-view-owner-2');
    $intruder = createTenant('pro-view-intruder');
    $customer = createCustomerFor($owner);
    $req = tenantRequestAs($intruder);

    try {
        app(UserCustomerController::class)->show($req, $customer);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a non-owner from updating another tenants customer with 404', function () {
    $owner = createTenant('pro-update-owner');
    $intruder = createTenant('pro-update-intruder');
    $customer = createCustomerFor($owner);

    // UpdateCustomerRequest — use a minimal FormRequest mock
    $req = tenantRequestAs($intruder, ['full_name' => 'Hacked'], 'PATCH');

    try {
        app(UserCustomerController::class)->update(
            UpdateCustomerRequest::createFrom($req),
            $customer
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a non-owner from deleting another tenants customer with 404', function () {
    $owner = createTenant('pro-destroy-owner');
    $intruder = createTenant('pro-destroy-intruder');
    $customer = createCustomerFor($owner);
    $req = tenantRequestAs($intruder, [], 'DELETE');

    try {
        app(UserCustomerController::class)->destroy($req, $customer);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a pending-deletion owner from updating a customer with 423', function () {
    $owner = createTenant('pro-pending-update');
    DB::connection('pgsql')->table('core.users')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $customer = createCustomerFor($owner);
    $req = tenantRequestAs($owner, ['full_name' => 'New Name'], 'PATCH');

    try {
        app(UserCustomerController::class)->update(
            UpdateCustomerRequest::createFrom($req),
            $customer
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks a pending-deletion owner from deleting a customer with 423', function () {
    $owner = createTenant('pro-pending-destroy');
    DB::connection('pgsql')->table('core.users')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $customer = createCustomerFor($owner);
    $req = tenantRequestAs($owner, [], 'DELETE');

    try {
        app(UserCustomerController::class)->destroy($req, $customer);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks a pending-deletion owner from creating a customer with 423', function () {
    $owner = createTenant('pro-pending-store');
    DB::connection('pgsql')->table('core.users')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    // Use a plain Request to bypass FormRequest validation — policy fires before any DB write.
    $req = tenantRequestAs($owner, [
        'full_name' => 'Test Customer',
        'email' => 'test@example.com',
        'source' => 'manual',
    ], 'POST');

    try {
        app(UserCustomerController::class)->store(
            StoreCustomerRequest::createFrom($req)
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});
