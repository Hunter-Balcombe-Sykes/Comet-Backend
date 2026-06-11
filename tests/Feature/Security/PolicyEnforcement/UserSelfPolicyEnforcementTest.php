<?php

use App\Http\Controllers\Api\User\Account\UserAccountDeletionController;
use App\Http\Controllers\Api\User\Account\UserSelfController;
use App\Http\Requests\Api\User\UpdateUserRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
});

// ── UserSelfController::update ─────────────────────────────────────────

it('blocks pending-deletion professional from updating their profile (423)', function () {
    $pro = createTenant('self-update-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, ['display_name' => 'Hacked'], 'PATCH');

    try {
        app(UserSelfController::class)->update(
            UpdateUserRequest::createFrom($req)
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows active professional to update their own profile (200)', function () {
    $pro = createTenant('self-update-active');
    $req = tenantRequestAs($pro, ['display_name' => 'New Name'], 'PATCH');

    $formReq = UpdateUserRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(UserSelfController::class)->update($formReq);

    expect($response->getStatusCode())->toBe(200);
});

// ── UserAccountDeletionController::confirm ─────────────────────────

// ── AAL2 freshness gate (P2-01) ────────────────────────────────────────────

it('blocks profile update when fresh AAL2 required and token is aal1 (401)', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => true]);

    $pro = createTenant('self-update-aal1');
    $req = tenantRequestAs($pro, ['display_name' => 'Should fail'], 'PATCH');
    // tenantRequestAs does not set aal2 — aal defaults to aal1, amr has no MFA entries

    $formReq = UpdateUserRequest::createFrom($req);
    $formReq->setContainer(app());

    try {
        app(UserSelfController::class)->update($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(401);
    }
});

it('allows profile update when fresh AAL2 required and amr contains recent totp', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => true]);

    $pro = createTenant('self-update-aal2');
    $req = tenantRequestAs($pro, ['display_name' => 'Should pass'], 'PATCH');
    $req->attributes->set('supabase_aal', 'aal2');
    $req->attributes->set('supabase_amr', [
        ['method' => 'password', 'timestamp' => time() - 600],
        ['method' => 'totp',     'timestamp' => time() - 60],
    ]);

    $formReq = UpdateUserRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();
    // Bind the enriched request so BasePolicy::requiresFreshAal2() sees the aal2 attributes.
    app()->instance('request', $formReq);

    $response = app(UserSelfController::class)->update($formReq);

    expect($response->getStatusCode())->toBe(200);
});

it('skips fresh-AAL2 check when feature flag is off (default)', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => false]);

    $pro = createTenant('self-update-flag-off');
    $req = tenantRequestAs($pro, ['display_name' => 'Should pass'], 'PATCH');

    $formReq = UpdateUserRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(UserSelfController::class)->update($formReq);

    expect($response->getStatusCode())->toBe(200);
});

// ── UserAccountDeletionController::confirm ─────────────────────────

it('blocks pending-deletion professional from confirming deletion via policy (423)', function () {
    $pro = createTenant('del-confirm-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    // Token must be ≥32 chars to pass the validation rule; policy fires before the service.
    $req = tenantRequestAs($pro, ['token' => Str::random(32)], 'POST');
    $req->attributes->set('professional', $pro);

    try {
        app(UserAccountDeletionController::class)->confirm($req);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
