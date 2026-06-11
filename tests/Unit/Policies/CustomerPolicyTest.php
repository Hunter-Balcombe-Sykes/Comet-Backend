<?php

use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use App\Policies\CustomerPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new CustomerPolicy;
});

// --- view ---

it('allows view when the actor owns the customer', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $customer = new Customer(['user_id' => 'pro-1']);

    expect($this->policy->view($actor, $customer))->toBeTrue();
});

it('denies view with 404 when the actor does not own the customer', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $customer = new Customer(['user_id' => 'pro-2']);

    $result = $this->policy->view($actor, $customer);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

// --- create ---

it('allows create when the actor owns the skeleton and is active', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $skeleton = new Customer(['user_id' => 'pro-1']);

    expect($this->policy->create($actor, $skeleton))->toBeTrue();
});

it('denies create as false (not 404) when the skeleton targets another professional', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $skeleton = new Customer(['user_id' => 'pro-other']);

    expect($this->policy->create($actor, $skeleton))->toBeFalse();
});

it('denies create with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $skeleton = new Customer(['user_id' => 'pro-1']);

    $result = $this->policy->create($actor, $skeleton);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// --- update ---

it('allows update when the actor owns the customer and is active', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $customer = new Customer(['user_id' => 'pro-1']);

    expect($this->policy->update($actor, $customer))->toBeTrue();
});

it('denies update with 404 when the actor does not own the customer', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $customer = new Customer(['user_id' => 'pro-2']);

    $result = $this->policy->update($actor, $customer);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $customer = new Customer(['user_id' => 'pro-1']);

    $result = $this->policy->update($actor, $customer);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// --- delete (delegates to update) ---

it('allows delete when the actor owns the customer', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $customer = new Customer(['user_id' => 'pro-1']);

    expect($this->policy->delete($actor, $customer))->toBeTrue();
});

it('denies delete with 404 when the actor does not own the customer', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $customer = new Customer(['user_id' => 'pro-2']);

    $result = $this->policy->delete($actor, $customer);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

it('denies delete with 423 when the actor is pending deletion', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $customer = new Customer(['user_id' => 'pro-1']);

    $result = $this->policy->delete($actor, $customer);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});
