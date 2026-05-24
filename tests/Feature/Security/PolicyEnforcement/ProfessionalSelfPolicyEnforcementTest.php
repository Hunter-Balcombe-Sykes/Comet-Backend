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
