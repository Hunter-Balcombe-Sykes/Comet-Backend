<?php

// IntegrationConnectionPolicy enforces 404-not-403 for non-owners on view/update/delete
// so callers cannot enumerate whether a connection exists. view() has NO pending-deletion
// guard — reads stay available while an account winds down. create() returns a bare bool
// (true/false) because there is no existing row whose existence to hide; 403 is correct there.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Policies\IntegrationConnectionPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new IntegrationConnectionPolicy;
});

// ---------------------------------------------------------------------------
// view
// ---------------------------------------------------------------------------

describe('view', function () {
    it('allows view when the actor owns the connection', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $conn = new IntegrationConnection(['user_id' => 'pro-actor']);

        expect($this->policy->view($actor, $conn))->toBeTrue();
    });

    it('denies view with 404 when the actor does not own the connection', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $conn = new IntegrationConnection(['user_id' => 'pro-other']);

        $result = $this->policy->view($actor, $conn);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(404);
    });

    it('allows view even when the owner is pending deletion (reads stay available)', function () {
        // view() has no denyIfPendingDeletion guard — the actor can still read their
        // own connections while the deletion is in progress.
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $conn = new IntegrationConnection(['user_id' => 'pro-actor']);

        expect($this->policy->view($actor, $conn))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

describe('update', function () {
    it('allows update when the active owner', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $conn = new IntegrationConnection(['user_id' => 'pro-actor']);

        expect($this->policy->update($actor, $conn))->toBeTrue();
    });

    it('denies update with 404 for a non-owner', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $conn = new IntegrationConnection(['user_id' => 'pro-other']);

        $result = $this->policy->update($actor, $conn);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(404);
    });

    it('denies update with 423 when the owner is pending deletion', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $conn = new IntegrationConnection(['user_id' => 'pro-actor']);

        $result = $this->policy->update($actor, $conn);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(423);
        expect($result->message())->toBe('Account is pending deletion.');
    });
});

// ---------------------------------------------------------------------------
// delete — delegates to update(), so behavior is identical
// ---------------------------------------------------------------------------

describe('delete', function () {
    it('allows delete for the active owner', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $conn = new IntegrationConnection(['user_id' => 'pro-actor']);

        expect($this->policy->delete($actor, $conn))->toBeTrue();
    });

    it('denies delete with 404 for a non-owner', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $conn = new IntegrationConnection(['user_id' => 'pro-other']);

        $result = $this->policy->delete($actor, $conn);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(404);
    });

    it('denies delete with 423 when the owner is pending deletion', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $conn = new IntegrationConnection(['user_id' => 'pro-actor']);

        $result = $this->policy->delete($actor, $conn);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(423);
    });
});

// ---------------------------------------------------------------------------
// create
// ---------------------------------------------------------------------------

describe('create', function () {
    it('allows create when the skeleton belongs to the active actor', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $skeleton = new IntegrationConnection(['user_id' => 'pro-actor']);

        expect($this->policy->create($actor, $skeleton))->toBeTrue();
    });

    it('denies create with 423 when the actor is pending deletion', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $skeleton = new IntegrationConnection(['user_id' => 'pro-actor']);

        $result = $this->policy->create($actor, $skeleton);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(423);
        expect($result->message())->toBe('Account is pending deletion.');
    });

    it('returns false (403, not 404) when the skeleton belongs to another user', function () {
        // create() has no existing row to hide, so it returns a bare bool rather than
        // denyAsNotFound() — false maps to 403, which is the correct shape for a
        // pre-creation ownership mismatch.
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $skeleton = new IntegrationConnection(['user_id' => 'pro-other']);

        expect($this->policy->create($actor, $skeleton))->toBeFalse();
    });
});
