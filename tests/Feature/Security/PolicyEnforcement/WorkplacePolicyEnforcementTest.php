<?php

/**
 * SEC-3 — UserWorkplaceController's write endpoints (upsert/destroy/
 * setPreviousWebsite) previously never called authorizeForUser, relying
 * solely on the HTTP-layer EnforcePendingDeletionReadOnly middleware. These
 * tests bypass that middleware (direct controller invocation) so they
 * actually exercise the new SitePolicy::update gate on $site.
 */

use App\Http\Controllers\Api\User\SiteManagement\UserWorkplaceController;
use App\Http\Requests\Api\User\Site\UpsertWorkplaceRequest;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupWorkplacesTable();
    setupBlocksTable();
});

function workplacePendingPro(string $handle): User
{
    $pro = createTenant($handle);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);

    return $pro->fresh()->load('site');
}

it('blocks a pending-deletion professional from upserting their workplace (423)', function () {
    $pro = workplacePendingPro('wp-upsert-pending');

    $req = tenantRequestAs($pro, ['name' => 'New Name'], 'PUT');
    $formReq = UpsertWorkplaceRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserWorkplaceController::class)->upsert($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('blocks a pending-deletion professional from deleting their workplace (423)', function () {
    $pro = workplacePendingPro('wp-destroy-pending');

    $req = tenantRequestAs($pro, [], 'DELETE');

    try {
        app(UserWorkplaceController::class)->destroy($req);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('blocks a pending-deletion professional from setting their previous website (423)', function () {
    $pro = workplacePendingPro('wp-prevweb-pending');

    $req = tenantRequestAs($pro, ['previous_website' => 'https://old.example.com'], 'PATCH');

    try {
        app(UserWorkplaceController::class)->setPreviousWebsite($req);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
