<?php

use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Policies\UserSelfPolicy;
use Illuminate\Auth\Access\Response;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    $this->policy = new UserSelfPolicy;
});

// --- view ---

it('allows cancelDeletion for the owner even when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $resource = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);

    expect($this->policy->cancelDeletion($actor, $resource))->toBeTrue();
});

it('denies cancelDeletion with 404 when the actor does not own the resource', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $resource = (new User)->forceFill(['id' => 'pro-2', 'status' => 'pending_deletion']);

    $result = $this->policy->cancelDeletion($actor, $resource);

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
