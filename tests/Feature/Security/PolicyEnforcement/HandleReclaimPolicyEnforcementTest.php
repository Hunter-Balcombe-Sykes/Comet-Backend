<?php

/**
 * SEC-8 — HandleReclaimController::store previously never called
 * authorizeForUser, relying solely on the HTTP-layer
 * EnforcePendingDeletionReadOnly middleware. Direct controller invocation
 * bypasses that middleware so this actually exercises the new
 * SitePolicy::update gate.
 */

use App\Http\Controllers\Api\User\Site\HandleReclaimController;
use App\Http\Requests\Api\User\Site\ReclaimHandleRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
});

it('blocks a pending-deletion professional from reclaiming a handle (423)', function () {
    $pro = createTenant('reclaim-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro = $pro->fresh()->load('site');

    $req = tenantRequestAs($pro, ['handle' => 'oldhandle'], 'POST');
    $formReq = ReclaimHandleRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(HandleReclaimController::class)->store($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
