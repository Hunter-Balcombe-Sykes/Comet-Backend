<?php

/**
 * SEC-2 — UserSectionBlockController's write endpoints (upsert/reorder/remove)
 * previously never called authorizeForUser at all, so a pending-deletion
 * professional's request reached the controller body with only the HTTP-layer
 * EnforcePendingDeletionReadOnly middleware standing in the way. These tests
 * bypass that middleware (direct controller invocation, mirroring
 * LinkBlockPolicyEnforcementTest) so they actually exercise the new
 * SitePolicy::create skeleton gate, not the HTTP edge.
 */

use App\Http\Controllers\Api\User\SiteManagement\UserSectionBlockController;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
});

function sectionBlockPendingPro(string $handle): User
{
    $pro = createTenant($handle);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);

    return $pro->fresh()->load('site');
}

it('blocks a pending-deletion professional from upserting a section block (423)', function () {
    $pro = sectionBlockPendingPro('sb-upsert-pending');

    $req = tenantRequestAs($pro, ['block_type' => 'gallery'], 'PUT');
    $formReq = UpsertSectionBlockRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserSectionBlockController::class)->upsert($formReq, 'gallery');
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('blocks a pending-deletion professional from reordering section blocks (423)', function () {
    $pro = sectionBlockPendingPro('sb-reorder-pending');

    $req = tenantRequestAs($pro, ['ids' => [(string) Str::uuid()]], 'POST');
    $formReq = ReorderBlocksRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserSectionBlockController::class)->reorder($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('blocks a pending-deletion professional from removing a section block (423)', function () {
    $pro = sectionBlockPendingPro('sb-remove-pending');

    $req = tenantRequestAs($pro, [], 'DELETE');

    try {
        app(UserSectionBlockController::class)->remove($req, 'gallery');
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
