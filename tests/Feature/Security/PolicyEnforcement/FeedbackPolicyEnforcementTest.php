<?php

/**
 * B23 — FeedbackPolicy enforcement (Feedback model).
 *
 * FeedbackPolicy is registered: Gate::policy(Feedback::class, FeedbackPolicy::class).
 *
 * Abilities tested:
 *   - view:    owner → true; non-owner → 404 (denyAsNotFound, enumeration defence).
 *   - delete:  owner → true; non-owner → 404.
 *   - viewAny: any authenticated user → true (query scope restricts to owner's rows).
 *   - create:  gated on AccountCapabilities::for($actor)->can_submit_feedback AND
 *              skeleton.user_id === actor.id. can_submit_feedback is always true for
 *              individual accounts (hardcoded in AccountCapabilities::individualCapabilities).
 *              No DB row needed — AccountCapabilities reads only $actor->status (in-memory).
 *
 * AccountCapabilities uses a WeakMap memo; flushCache() is called in beforeEach
 * so each test gets a fresh capability set even if the same $user instance is reused.
 *
 * All assertions go through Gate::forUser() — never via direct policy instantiation.
 */

use App\Models\Core\Feedback;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    // Clear the WeakMap cache so reassigning attributes on a User takes effect.
    AccountCapabilities::flushCache();
});

function feedbackPolicy_user(string $id): User
{
    $user = new User;
    $user->id = $id;
    // status='active' — ensures can_be_reported=true, but can_submit_feedback is
    // always true regardless of status per AccountCapabilities::individualCapabilities.
    $user->status = 'active';

    return $user;
}

function feedbackPolicy_feedback(string $userId): Feedback
{
    $fb = new Feedback;
    $fb->user_id = $userId;

    return $fb;
}

// --- view ---

it('allows the owner to view their feedback', function () {
    expect(
        Gate::forUser(feedbackPolicy_user('owner-1'))->allows('view', feedbackPolicy_feedback('owner-1'))
    )->toBeTrue();
});

it('returns 404 (not 403) when a non-owner tries to view feedback', function () {
    $response = Gate::forUser(feedbackPolicy_user('intruder-2'))->inspect('view', feedbackPolicy_feedback('owner-1'));

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// --- delete ---

it('allows the owner to delete their feedback', function () {
    expect(
        Gate::forUser(feedbackPolicy_user('owner-1'))->allows('delete', feedbackPolicy_feedback('owner-1'))
    )->toBeTrue();
});

it('returns 404 (not 403) when a non-owner tries to delete feedback', function () {
    $response = Gate::forUser(feedbackPolicy_user('intruder-2'))->inspect('delete', feedbackPolicy_feedback('owner-1'));

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// --- viewAny ---

it('allows any authenticated user to call viewAny (query scope gates the results)', function () {
    expect(
        Gate::forUser(feedbackPolicy_user('any-user'))->allows('viewAny', Feedback::class)
    )->toBeTrue();
});

// --- create ---

it('allows an owner to create feedback when can_submit_feedback is true', function () {
    $actor = feedbackPolicy_user('owner-1');
    $skeleton = feedbackPolicy_feedback('owner-1'); // skeleton.user_id matches actor

    expect(
        Gate::forUser($actor)->allows('create', $skeleton)
    )->toBeTrue();
});

it('denies create when the skeleton user_id does not match the actor', function () {
    $actor = feedbackPolicy_user('owner-1');
    $skeleton = feedbackPolicy_feedback('someone-else');

    expect(
        Gate::forUser($actor)->allows('create', $skeleton)
    )->toBeFalse();
});
