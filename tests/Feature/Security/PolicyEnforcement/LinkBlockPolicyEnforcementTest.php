<?php

use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalLinkBlockController;
use App\Http\Requests\Api\Professional\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use Illuminate\Auth\Access\AuthorizationException;
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
    $formReq->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('PATCH', '/', []), function ($route) use ($block) {
        $route->bind(request());
        $route->setParameter('linkBlock', $block);
    }));
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(ProfessionalLinkBlockController::class)->update($formReq, $block);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from updating a link block with 404', function () {
    $owner = createTenant('lb-update-owner-2');
    $intruder = createTenant('lb-update-intruder');
    $block = createLinkBlockFor($owner);
    $req = tenantRequestAs($intruder, ['title' => 'Hacked'], 'PATCH');

    try {
        app(ProfessionalLinkBlockController::class)->update(
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
        app(ProfessionalLinkBlockController::class)->update(
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
    $formReq->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('DELETE', '/', []), function ($route) use ($block) {
        $route->bind(request());
        $route->setParameter('linkBlock', $block);
    }));
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(ProfessionalLinkBlockController::class)->destroy($formReq, $block);

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
    $formReq->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('DELETE', '/', []), function ($route) use ($block) {
        $route->bind(request());
        $route->setParameter('linkBlock', $block);
    }));
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(ProfessionalLinkBlockController::class)->destroy($formReq, $block);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});
