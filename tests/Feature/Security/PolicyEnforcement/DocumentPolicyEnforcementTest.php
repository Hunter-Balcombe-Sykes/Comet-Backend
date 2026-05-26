<?php

use App\Http\Controllers\Api\User\Account\UserDocumentController;
use App\Http\Requests\Api\User\Documents\UpdateDocumentRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
    setupSubdomainAliasesTable();
});

it('allows the owner to update their own document', function () {
    // Fake storage so buildDocumentPayload() url() call doesn't hit real R2.
    Storage::fake(config('partna.media_disk'));

    $owner = createTenant('doc-update-owner');
    $document = createDocumentFor($owner);
    $req = tenantRequestAs($owner, ['title' => 'Updated Title'], 'PATCH');

    // Wire the container and run validation before the controller — mirrors the HTTP stack.
    $formReq = UpdateDocumentRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    $response = app(UserDocumentController::class)->update($formReq, $document);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from updating a document with 404', function () {
    $owner = createTenant('doc-update-owner-2');
    $intruder = createTenant('doc-update-intruder');
    $document = createDocumentFor($owner);
    $req = tenantRequestAs($intruder, ['title' => 'Hacked'], 'PATCH');

    try {
        app(UserDocumentController::class)->update(
            UpdateDocumentRequest::createFrom($req),
            $document
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a pending-deletion owner from updating a document with 423', function () {
    $owner = createTenant('doc-update-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $document = createDocumentFor($owner);
    $req = tenantRequestAs($owner, ['title' => 'New Title'], 'PATCH');

    try {
        app(UserDocumentController::class)->update(
            UpdateDocumentRequest::createFrom($req),
            $document
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows the owner to delete their own document', function () {
    Storage::fake(config('partna.media_disk'));

    $owner = createTenant('doc-destroy-owner-self');
    $document = createDocumentFor($owner);
    $req = tenantRequestAs($owner, [], 'DELETE');

    $response = app(UserDocumentController::class)->destroy($req, $document);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from deleting a document with 404', function () {
    $owner = createTenant('doc-destroy-owner');
    $intruder = createTenant('doc-destroy-intruder');
    $document = createDocumentFor($owner);
    $req = tenantRequestAs($intruder, [], 'DELETE');

    try {
        app(UserDocumentController::class)->destroy($req, $document);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});
