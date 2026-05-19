<?php

use App\Models\Core\Professional\Professional;
use App\Policies\CommissionPolicy;
use App\Services\Store\BrandAccessService;

beforeEach(function () {
    $this->brandAccess = Mockery::mock(BrandAccessService::class);
    $this->policy = new CommissionPolicy($this->brandAccess);
});

// CommissionPolicy::topUp() was removed alongside the legacy brand-wallet top-up flow
// (Phase 9 cleanup). Under v2 Option A the brand funds commission payouts via destination
// charges on their saved card/BECS PM — no wallet, no top-up. Authorization for the saved
// PM lifecycle is covered by managePaymentMethod below.

it('allows brand to managePaymentMethod on self only', function () {
    $brand = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'account_type' => 'brand', 'professional_type' => 'brand']);
    $other = (new Professional)->forceFill(['id' => 'brand-2', 'status' => 'active', 'account_type' => 'brand', 'professional_type' => 'brand']);
    expect($this->policy->managePaymentMethod($brand, $brand))->toBeTrue();
    expect($this->policy->managePaymentMethod($brand, $other))->toBeFalse();
});

it('forbids non-brand account_type from managePaymentMethod', function () {
    $aff = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'account_type' => 'partner', 'professional_type' => 'affiliate']);
    expect($this->policy->managePaymentMethod($aff, $aff))->toBeFalse();
});
