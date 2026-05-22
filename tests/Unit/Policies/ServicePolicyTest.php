<?php

use App\Models\Core\Professional\User;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use App\Policies\ServicePolicy;

beforeEach(function () {
    $this->policy = new ServicePolicy;
});

// --- view (Service) ---

it('allows view when the actor owns the service', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $service = new Service(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $service))->toBeTrue();
});

it('denies view with 404 when the actor does not own the service', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $service = new Service(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $service);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// --- view (ServiceCategory) ---

it('allows view when the actor owns the service category', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $category = new ServiceCategory(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $category))->toBeTrue();
});

it('denies view with 404 when the actor does not own the service category', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $category = new ServiceCategory(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $category);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// --- create ---

it('allows create when the actor owns the skeleton and is active', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $skeleton = new Service(['professional_id' => 'pro-1']);

    expect($this->policy->create($actor, $skeleton))->toBeTrue();
});

it('denies create as false (not 404) when the skeleton targets another professional', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $skeleton = new Service(['professional_id' => 'pro-other']);

    expect($this->policy->create($actor, $skeleton))->toBeFalse();
});

it('denies create with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $skeleton = new Service(['professional_id' => 'pro-1']);

    $result = $this->policy->create($actor, $skeleton);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('denies category create with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $skeleton = new ServiceCategory(['professional_id' => 'pro-1']);

    $result = $this->policy->create($actor, $skeleton);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
});

// --- update ---

it('allows update when the actor owns the service and is active', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $service = new Service(['professional_id' => 'pro-1']);

    expect($this->policy->update($actor, $service))->toBeTrue();
});

it('denies update with 404 when the actor does not own the service', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $service = new Service(['professional_id' => 'pro-2']);

    $result = $this->policy->update($actor, $service);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $service = new Service(['professional_id' => 'pro-1']);

    $result = $this->policy->update($actor, $service);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('allows update when the actor owns the category and is active', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $category = new ServiceCategory(['professional_id' => 'pro-1']);

    expect($this->policy->update($actor, $category))->toBeTrue();
});

it('denies update with 404 when the actor does not own the category', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $category = new ServiceCategory(['professional_id' => 'pro-2']);

    $result = $this->policy->update($actor, $category);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// --- delete (delegates to update) ---

it('allows delete when the actor owns the service', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $service = new Service(['professional_id' => 'pro-1']);

    expect($this->policy->delete($actor, $service))->toBeTrue();
});

it('denies delete with 404 when the actor does not own the service', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $service = new Service(['professional_id' => 'pro-2']);

    $result = $this->policy->delete($actor, $service);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies delete with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $service = new Service(['professional_id' => 'pro-1']);

    $result = $this->policy->delete($actor, $service);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});
