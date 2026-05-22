<?php

use App\Models\Core\Professional\User;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

// Concrete subclass purely for testing the protected helper.
class FakePolicy extends BasePolicy
{
    public function callDenyIfPendingDeletion(User $professional): ?Response
    {
        return $this->denyIfPendingDeletion($professional);
    }
}

it('returns null when the professional is active', function () {
    $pro = new User(['status' => 'active']);

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});

it('returns a 423 deny response when the professional is pending deletion', function () {
    $pro = new User(['status' => 'pending_deletion']);

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('returns null when the professional has any other status', function () {
    $pro = new User(['status' => 'suspended']);

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});

it('returns a 404 deny response from denyAsNotFound', function () {
    $policy = new class extends \App\Policies\BasePolicy
    {
        public function callDenyAsNotFound(): \Illuminate\Auth\Access\Response
        {
            return $this->denyAsNotFound();
        }
    };

    $result = $policy->callDenyAsNotFound();

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
    expect($result->message())->toBe('Not found.');
});
