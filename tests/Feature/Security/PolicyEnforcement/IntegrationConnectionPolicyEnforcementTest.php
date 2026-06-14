<?php

/**
 * TEST-13 — IntegrationConnectionPolicy enforcement via the Gate.
 *
 * Ownership lives in the raw `user_id` attribute on the model (no DB row needed
 * for ownership checks). Setting $model->user_id populates getAttributes() which
 * is what ownerMatches() reads in the policy.
 *
 * Abilities:
 *   view   — owner → true; non-owner → 404.
 *   update — owner → true; non-owner → 404; owner pending_deletion → 423.
 *   delete — same shape as update (delegates to update internally).
 *   create — skeleton.user_id === actor.id → true; mismatch → false/404;
 *            pending_deletion actor → 423.
 *
 * The 423 (pending_deletion) check fires BEFORE the ownership check in update/
 * delete/create — so a pending actor is denied even when they own the resource.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Helpers — file-unique names.
// ---------------------------------------------------------------------------

/**
 * Build an in-memory User actor with the given status and a fresh UUID.
 */
function integrationPolicy_actor(string $status = 'active'): User
{
    $actor = new User;
    $actor->id = (string) Str::uuid();
    $actor->status = $status;

    return $actor;
}

/**
 * Build an in-memory IntegrationConnection with user_id set to $ownerId.
 * Setting the attribute via array-access populates getAttributes() which is
 * what IntegrationConnectionPolicy::ownerMatches() reads.
 */
function integrationPolicy_connection(string $ownerId): IntegrationConnection
{
    $conn = new IntegrationConnection;
    $conn->user_id = $ownerId;

    return $conn;
}

// ---------------------------------------------------------------------------
// view
// ---------------------------------------------------------------------------

it('view: owner actor → allowed', function () {
    $actor = integrationPolicy_actor();
    $resource = integrationPolicy_connection($actor->id);

    expect(
        Gate::forUser($actor)->allows('view', $resource)
    )->toBeTrue();
});

it('view: non-owner actor → 404', function () {
    $actor = integrationPolicy_actor();
    $resource = integrationPolicy_connection((string) Str::uuid()); // different owner

    $response = Gate::forUser($actor)->inspect('view', $resource);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

it('update: owner (active) → allowed', function () {
    $actor = integrationPolicy_actor('active');
    $resource = integrationPolicy_connection($actor->id);

    expect(
        Gate::forUser($actor)->allows('update', $resource)
    )->toBeTrue();
});

it('update: non-owner → 404', function () {
    $actor = integrationPolicy_actor('active');
    $resource = integrationPolicy_connection((string) Str::uuid());

    $response = Gate::forUser($actor)->inspect('update', $resource);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('update: owner who is pending_deletion → 423 (pending_deletion fires before ownership)', function () {
    $actor = integrationPolicy_actor('pending_deletion');
    // Even though actor owns the resource, pending_deletion fires first.
    $resource = integrationPolicy_connection($actor->id);

    $response = Gate::forUser($actor)->inspect('update', $resource);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});

// ---------------------------------------------------------------------------
// delete (delegates to update internally — mirror the update shape)
// ---------------------------------------------------------------------------

it('delete: owner (active) → allowed', function () {
    $actor = integrationPolicy_actor('active');
    $resource = integrationPolicy_connection($actor->id);

    expect(
        Gate::forUser($actor)->allows('delete', $resource)
    )->toBeTrue();
});

it('delete: non-owner → 404', function () {
    $actor = integrationPolicy_actor('active');
    $resource = integrationPolicy_connection((string) Str::uuid());

    $response = Gate::forUser($actor)->inspect('delete', $resource);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('delete: owner who is pending_deletion → 423', function () {
    $actor = integrationPolicy_actor('pending_deletion');
    $resource = integrationPolicy_connection($actor->id);

    $response = Gate::forUser($actor)->inspect('delete', $resource);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});

// ---------------------------------------------------------------------------
// create (skeleton: user_id on the new model instance before it is persisted)
// ---------------------------------------------------------------------------

it('create: skeleton.user_id matches actor → allowed', function () {
    $actor = integrationPolicy_actor('active');
    $skeleton = integrationPolicy_connection($actor->id); // pre-create ownership check

    expect(
        Gate::forUser($actor)->allows('create', $skeleton)
    )->toBeTrue();
});

it('create: skeleton.user_id does not match actor → denied (false, 403)', function () {
    $actor = integrationPolicy_actor('active');
    $skeleton = integrationPolicy_connection((string) Str::uuid()); // wrong owner on skeleton

    expect(
        Gate::forUser($actor)->allows('create', $skeleton)
    )->toBeFalse();
});

it('create: pending_deletion actor → 423 regardless of skeleton ownership', function () {
    $actor = integrationPolicy_actor('pending_deletion');
    $skeleton = integrationPolicy_connection($actor->id); // actor owns skeleton

    $response = Gate::forUser($actor)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
