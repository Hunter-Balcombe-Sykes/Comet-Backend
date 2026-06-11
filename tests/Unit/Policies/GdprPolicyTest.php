<?php

use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\User\User;
use App\Policies\GdprPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new GdprPolicy;
});

// --- view on GdprRequest ---

it('allows view when the actor owns the GdprRequest', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $request = new GdprRequest(['user_id' => 'pro-1']);

    expect($this->policy->view($actor, $request))->toBeTrue();
});

it('denies view with 404 when the actor does not own the GdprRequest', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $request = new GdprRequest(['user_id' => 'pro-2']);

    $result = $this->policy->view($actor, $request);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

// --- view on DataExportAudit ---

it('allows view when the actor owns the DataExportAudit', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $audit = new DataExportAudit(['user_id' => 'pro-1']);

    expect($this->policy->view($actor, $audit))->toBeTrue();
});

it('denies view with 404 when the actor does not own the DataExportAudit', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $audit = new DataExportAudit(['user_id' => 'pro-2']);

    $result = $this->policy->view($actor, $audit);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

// --- CRITICAL: pending_deletion does NOT block GDPR access ---

it('allows view for a pending_deletion actor on their own GdprRequest', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $request = new GdprRequest(['user_id' => 'pro-1']);

    expect($this->policy->view($actor, $request))->toBeTrue();
});

it('allows view for a pending_deletion actor on their own DataExportAudit', function () {
    $actor = (new User)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $audit = new DataExportAudit(['user_id' => 'pro-1']);

    expect($this->policy->view($actor, $audit))->toBeTrue();
});
