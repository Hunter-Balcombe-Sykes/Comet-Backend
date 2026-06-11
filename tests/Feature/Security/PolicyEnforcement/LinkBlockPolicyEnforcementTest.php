<?php

use App\Http\Controllers\Api\User\SiteManagement\UserLinkBlockController;
use App\Http\Requests\Api\User\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\User\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
});

it('allows the owner to update their own link block', function () {
    $owner = createTenant('lb-update-owner');
    $block = createLinkBlockFor($owner);
    $req = tenantRequestAs($owner, ['title' => 'Updated', 'url' => 'https://new.example.com'], 'PATCH');

    // Wire the route binding so prepareForValidation can extract the block UUID,
    // then run validation before the controller — mirrors how the HTTP stack works.
    $formReq = UpdateLinkBlockRequest::createFrom($req);
    $formReq->setRouteResolver(fn () => tap(new Route('PATCH', '/', []), function ($route) use ($block) {
        $route->bind(request());
        $route->setParameter('linkBlock', $block);
    }));
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(UserLinkBlockController::class)->update($formReq, $block);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from updating a link block with 404', function () {
    $owner = createTenant('lb-update-owner-2');
    $intruder = createTenant('lb-update-intruder');
    $block = createLinkBlockFor($owner);
    $req = tenantRequestAs($intruder, ['title' => 'Hacked'], 'PATCH');

    try {
        app(UserLinkBlockController::class)->update(
            UpdateLinkBlockRequest::createFrom($req),
            $block
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a pending-deletion owner from updating a link block with 423', function () {
    $owner = createTenant('lb-update-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $block = createLinkBlockFor($owner);
    $req = tenantRequestAs($owner, ['title' => 'Updated'], 'PATCH');

    try {
        app(UserLinkBlockController::class)->update(
            UpdateLinkBlockRequest::createFrom($req),
            $block
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows the owner to delete their own link block', function () {
    $owner = createTenant('lb-destroy-owner');
    $block = createLinkBlockFor($owner);
    $req = tenantRequestAs($owner, [], 'DELETE');

    // Wire the route binding so prepareForValidation can extract the block UUID.
    $formReq = DestroyLinkBlockRequest::createFrom($req);
    $formReq->setRouteResolver(fn () => tap(new Route('DELETE', '/', []), function ($route) use ($block) {
        $route->bind(request());
        $route->setParameter('linkBlock', $block);
    }));
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(UserLinkBlockController::class)->destroy($formReq, $block);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from deleting a link block with 404', function () {
    $owner = createTenant('lb-destroy-owner-2');
    $intruder = createTenant('lb-destroy-intruder');
    $block = createLinkBlockFor($owner);
    $req = tenantRequestAs($intruder, [], 'DELETE');

    // destroy() calls validated() before the policy check, so we must resolve
    // the validator first — same pattern as the happy-path tests above.
    $formReq = DestroyLinkBlockRequest::createFrom($req);
    $formReq->setRouteResolver(fn () => tap(new Route('DELETE', '/', []), function ($route) use ($block) {
        $route->bind(request());
        $route->setParameter('linkBlock', $block);
    }));
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserLinkBlockController::class)->destroy($formReq, $block);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

// ── store() ────────────────────────────────────────────────────────────────

it('blocks pending-deletion professional from creating a link block (423)', function () {
    $pro = createTenant('lb-store-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, [
        'title' => 'My Link',
        'url' => 'https://example.com',
    ], 'POST');

    try {
        app(UserLinkBlockController::class)
            ->store(StoreLinkBlockRequest::createFrom($req));
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows active professional to create a link block (no 423 thrown)', function () {
    $pro = createTenant('lb-store-active');

    $req = tenantRequestAs($pro, [
        'title' => 'My Link',
        'url' => 'https://example.com',
        'category' => 'other',
    ], 'POST');
    $req->headers->set('Accept', 'application/json');

    $formReq = StoreLinkBlockRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    // Verify the authorization gate does not block an active professional.
    // The store() method passes the policy check and proceeds to the DB insert;
    // we catch QueryException (pg_advisory_xact_lock is Postgres-only, not SQLite)
    // to confirm auth succeeded without testing the full DB flow here.
    $authBlocked = false;
    try {
        app(UserLinkBlockController::class)
            ->store($formReq);
    } catch (AuthorizationException $e) {
        $authBlocked = true;
    } catch (QueryException $e) {
        // Advisory lock uses pg_advisory_xact_lock(hashtext(?)) — not available in
        // the SQLite test driver. Reaching this point means auth passed.
    }

    expect($authBlocked)->toBeFalse();
});

// ── reorder() ──────────────────────────────────────────────────────────────

it('blocks pending-deletion professional from reordering link blocks (423)', function () {
    $pro = createTenant('lb-reorder-pending');
    $block = createLinkBlockFor($pro);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, ['ids' => [$block->id]], 'POST');

    try {
        app(UserLinkBlockController::class)
            ->reorder(ReorderBlocksRequest::createFrom($req));
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows active professional to reorder their link blocks (no 423 thrown)', function () {
    $pro = createTenant('lb-reorder-active');
    $block = createLinkBlockFor($pro);

    $req = tenantRequestAs($pro, ['ids' => [$block->id]], 'POST');

    $formReq = ReorderBlocksRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    // Verify the authorization gate does not block an active professional.
    // The reorder() method passes the policy check and proceeds to advisory-lock
    // DB queries; we catch QueryException (pg_advisory_xact_lock / hashtext are
    // Postgres-only) to confirm auth succeeded without testing the full DB flow.
    $authBlocked = false;
    try {
        app(UserLinkBlockController::class)
            ->reorder($formReq);
    } catch (AuthorizationException $e) {
        $authBlocked = true;
    } catch (QueryException $e) {
        // Advisory lock uses pg_advisory_xact_lock(hashtext(?)) — not available in
        // the SQLite test driver. Reaching this point means auth passed.
    }

    expect($authBlocked)->toBeFalse();
});
