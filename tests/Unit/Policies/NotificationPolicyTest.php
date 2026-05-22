<?php

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Notifications\NotificationEmailPolicy;
use App\Models\Core\Notifications\NotificationEmailPreference;
use App\Models\Core\Notifications\NotificationReceipt;
use App\Models\Core\Professional\User;
use App\Policies\NotificationPolicy;

beforeEach(function () {
    $this->policy = new NotificationPolicy;
});

// ---------------------------------------------------------------------------
// view — Notification (global broadcast)
// ---------------------------------------------------------------------------

it('allows any actor to view a global notification (professional_id null)', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => null]);

    expect($this->policy->view($actor, $notification))->toBeTrue();
});

it('allows a different actor to view a global notification (professional_id null)', function () {
    $actor = (new User)->forceFill(['id' => 'pro-other', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => null]);

    expect($this->policy->view($actor, $notification))->toBeTrue();
});

// ---------------------------------------------------------------------------
// view — Notification (targeted)
// ---------------------------------------------------------------------------

it('allows view when the actor owns a targeted notification', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $notification))->toBeTrue();
});

it('denies view with 404 when the targeted notification belongs to another professional', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $notification);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// view — other model types (standard ownership)
// ---------------------------------------------------------------------------

it('allows view when the actor owns a NotificationEmailPreference', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = new NotificationEmailPreference(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $pref))->toBeTrue();
});

it('denies view with 404 when the actor does not own the NotificationEmailPreference', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = new NotificationEmailPreference(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $pref);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('allows view when the actor owns an EmailSubscription', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $sub = new EmailSubscription(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $sub))->toBeTrue();
});

it('denies view with 404 when the actor does not own the EmailSubscription', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $sub = new EmailSubscription(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $sub);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

it('allows update when the actor owns the resource and is active', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-1']);

    expect($this->policy->update($actor, $notification))->toBeTrue();
});

it('denies update with 404 when the actor does not own the resource', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-2']);

    $result = $this->policy->update($actor, $notification);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-1']);

    $result = $this->policy->update($actor, $notification);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('denies update with 404 when a global notification (professional_id null) is passed', function () {
    // Global notifications are broadcasts — individual actors cannot mutate them.
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => null]);

    $result = $this->policy->update($actor, $notification);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// delete — delegates to update
// ---------------------------------------------------------------------------

it('allows delete when the actor owns the resource', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-1']);

    expect($this->policy->delete($actor, $notification))->toBeTrue();
});

it('denies delete with 404 when the actor does not own the resource', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-2']);

    $result = $this->policy->delete($actor, $notification);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies delete with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $notification = (new Notification)->forceFill(['professional_id' => 'pro-1']);

    $result = $this->policy->delete($actor, $notification);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// ---------------------------------------------------------------------------
// view — NotificationEmailPolicy (direct ownership, no broadcast concept)
// ---------------------------------------------------------------------------

it('allows view on NotificationEmailPolicy for the owner', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = new NotificationEmailPolicy(['professional_id' => 'pro-1']);
    expect($this->policy->view($actor, $pref))->toBeTrue();
});

it('denies view on NotificationEmailPolicy with 404 for non-owner', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = new NotificationEmailPolicy(['professional_id' => 'pro-other']);
    $result = $this->policy->view($actor, $pref);
    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// view — NotificationReceipt (direct ownership)
// ---------------------------------------------------------------------------

it('allows view on NotificationReceipt for the owner', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $receipt = new NotificationReceipt(['professional_id' => 'pro-1']);
    expect($this->policy->view($actor, $receipt))->toBeTrue();
});

it('denies view on NotificationReceipt with 404 for non-owner', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $receipt = new NotificationReceipt(['professional_id' => 'pro-other']);
    $result = $this->policy->view($actor, $receipt);
    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});
