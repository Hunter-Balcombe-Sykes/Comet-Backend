<?php

use App\Models\Core\User\User;
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
    $pro = (new User)->forceFill(['status' => 'active']); // B11 SEC-2: status no longer fillable

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});

it('returns a 423 deny response when the professional is pending deletion', function () {
    $pro = (new User)->forceFill(['status' => 'pending_deletion']); // B11 SEC-2

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('returns null when the professional has any other status', function () {
    $pro = (new User)->forceFill(['status' => 'suspended']); // B11 SEC-2

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});

it('returns a 404 deny response from denyAsNotFound', function () {
    $policy = new class extends BasePolicy
    {
        public function callDenyAsNotFound(): Response
        {
            return $this->denyAsNotFound();
        }
    };

    $result = $policy->callDenyAsNotFound();

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
    expect($result->message())->toBe('Not found.');
});
