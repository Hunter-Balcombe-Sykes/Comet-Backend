<?php

use App\Models\Core\User\User;
use App\Models\Core\User\UserConfirmationPreference;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Policies\UserSelfPolicy;
use Illuminate\Auth\Access\Response;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    $this->policy = new UserSelfPolicy;
});

// --- view ---

it('allows view when the actor owns a UserConfirmationPreference', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new UserConfirmationPreference)->forceFill(['user_id' => 'pro-1']);

    expect($this->policy->view($actor, $pref))->toBeTrue();
});

it('denies view with 404 when the actor does not own a UserConfirmationPreference', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new UserConfirmationPreference)->forceFill(['user_id' => 'pro-2']);

    $result = $this->policy->view($actor, $pref);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

// --- update ---

it('allows update when the actor owns the resource and is active', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new UserConfirmationPreference)->forceFill(['user_id' => 'pro-1']);

    expect($this->policy->update($actor, $pref))->toBeTrue();
});

it('denies update with 404 when the actor does not own the resource', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new UserConfirmationPreference)->forceFill(['user_id' => 'pro-2']);

    $result = $this->policy->update($actor, $pref);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $pref = (new UserConfirmationPreference)->forceFill(['user_id' => 'pro-1']);

    $result = $this->policy->update($actor, $pref);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// --- delete (delegates to update) ---

it('allows delete when the actor owns the resource', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new UserConfirmationPreference)->forceFill(['user_id' => 'pro-1']);

    expect($this->policy->delete($actor, $pref))->toBeTrue();
});

it('denies delete with 404 when the actor does not own the resource', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new UserConfirmationPreference)->forceFill(['user_id' => 'pro-2']);

    $result = $this->policy->delete($actor, $pref);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

// --- audit-log immutability ---

it('denies update on UserDeletionAuditEntry even for the owner (append-only)', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $audit = new UserDeletionAuditEntry(['user_id' => 'pro-1']);

    $result = $this->policy->update($actor, $audit);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

it('denies delete on UserDeletionAuditEntry even for the owner (append-only)', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $audit = new UserDeletionAuditEntry(['user_id' => 'pro-1']);

    $result = $this->policy->delete($actor, $audit);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});
