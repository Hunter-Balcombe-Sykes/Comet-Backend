<?php

use App\Http\Controllers\Api\Professional\Account\ProfessionalAccountDeletionController;
use App\Http\Controllers\Api\Professional\Account\ProfessionalController;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
});

// ── ProfessionalController::update ─────────────────────────────────────────

it('blocks pending-deletion professional from updating their profile (423)', function () {
    $pro = createTenant('self-update-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, ['display_name' => 'Hacked'], 'PATCH');

    try {
        app(ProfessionalController::class)->update(
            UpdateProfessionalRequest::createFrom($req)
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows active professional to update their own profile (200)', function () {
    $pro = createTenant('self-update-active');
    $req = tenantRequestAs($pro, ['display_name' => 'New Name'], 'PATCH');

    $formReq = UpdateProfessionalRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(ProfessionalController::class)->update($formReq);

    expect($response->getStatusCode())->toBe(200);
});

// ── ProfessionalAccountDeletionController::confirm ─────────────────────────

// ── AAL2 freshness gate (P2-01) ────────────────────────────────────────────

it('blocks profile update when fresh AAL2 required and token is aal1 (401)', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => true]);

    $pro = createTenant('self-update-aal1');
    $req = tenantRequestAs($pro, ['display_name' => 'Should fail'], 'PATCH');
    // tenantRequestAs does not set aal2 — aal defaults to aal1, amr has no MFA entries

    $formReq = \App\Http\Requests\Api\Professional\UpdateProfessionalRequest::createFrom($req);
    $formReq->setContainer(app());

    try {
        app(\App\Http\Controllers\Api\Professional\Account\ProfessionalController::class)->update($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
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

    $formReq = \App\Http\Requests\Api\Professional\UpdateProfessionalRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();
    // Bind the enriched request so BasePolicy::requiresFreshAal2() sees the aal2 attributes.
    app()->instance('request', $formReq);

    $response = app(\App\Http\Controllers\Api\Professional\Account\ProfessionalController::class)->update($formReq);

    expect($response->getStatusCode())->toBe(200);
});

it('skips fresh-AAL2 check when feature flag is off (default)', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => false]);

    $pro = createTenant('self-update-flag-off');
    $req = tenantRequestAs($pro, ['display_name' => 'Should pass'], 'PATCH');

    $formReq = \App\Http\Requests\Api\Professional\UpdateProfessionalRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(\App\Http\Controllers\Api\Professional\Account\ProfessionalController::class)->update($formReq);

    expect($response->getStatusCode())->toBe(200);
});

// ── ProfessionalAccountDeletionController::confirm ─────────────────────────

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
        app(ProfessionalAccountDeletionController::class)->confirm($req);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
