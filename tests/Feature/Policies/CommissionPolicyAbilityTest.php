<?php

use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use App\Policies\CommissionPolicy;
use App\Services\Store\BrandAccessService;

beforeEach(function () {
    $this->brandAccess = Mockery::mock(BrandAccessService::class);
    $this->policy = new CommissionPolicy($this->brandAccess);
});

// ─── viewOwnPayouts ────────────────────────────────────────────────────────────

it('viewOwnPayouts: brand can view their own brand-side payouts', function () {
    $brand = (new Professional)->forceFill(['id' => 'b-1', 'account_type' => 'brand', 'professional_type' => 'brand']);
    $skeleton = new CommissionPayout;
    $skeleton->forceFill(['brand_professional_id' => 'b-1']);

    expect($this->policy->viewOwnPayouts($brand, $skeleton))->toBeTrue();
});

it('viewOwnPayouts: brand denied viewing affiliate-side payouts', function () {
    $brand = (new Professional)->forceFill(['id' => 'b-1', 'account_type' => 'brand', 'professional_type' => 'brand']);
    $skeleton = new CommissionPayout;
    $skeleton->forceFill(['affiliate_professional_id' => 'b-1']);

    expect($this->policy->viewOwnPayouts($brand, $skeleton))->toBeFalse();
});

it('viewOwnPayouts: partner can view their own affiliate-side payouts', function () {
    $partner = (new Professional)->forceFill(['id' => 'p-1', 'account_type' => 'partner', 'professional_type' => 'affiliate']);
    $skeleton = new CommissionPayout;
    $skeleton->forceFill(['affiliate_professional_id' => 'p-1']);

    expect($this->policy->viewOwnPayouts($partner, $skeleton))->toBeTrue();
});

it('viewOwnPayouts: partner denied viewing brand-side payouts', function () {
    $partner = (new Professional)->forceFill(['id' => 'p-1', 'account_type' => 'partner', 'professional_type' => 'affiliate']);
    $skeleton = new CommissionPayout;
    $skeleton->forceFill(['brand_professional_id' => 'p-1']);

    expect($this->policy->viewOwnPayouts($partner, $skeleton))->toBeFalse();
});

it('viewOwnPayouts: individual denied viewing brand-side payouts', function () {
    $individual = (new Professional)->forceFill(['id' => 'i-1', 'account_type' => 'individual', 'professional_type' => 'professional']);
    $skeleton = new CommissionPayout;
    $skeleton->forceFill(['brand_professional_id' => 'i-1']);

    expect($this->policy->viewOwnPayouts($individual, $skeleton))->toBeFalse();
});

it('viewOwnPayouts: individual allowed viewing their own affiliate-side payouts', function () {
    $individual = (new Professional)->forceFill(['id' => 'i-1', 'account_type' => 'individual', 'professional_type' => 'professional']);
    $skeleton = new CommissionPayout;
    $skeleton->forceFill(['affiliate_professional_id' => 'i-1']);

    expect($this->policy->viewOwnPayouts($individual, $skeleton))->toBeTrue();
});

// ─── managePaymentMethod ───────────────────────────────────────────────────────

it('managePaymentMethod: brand allowed for self', function () {
    $brand = (new Professional)->forceFill(['id' => 'b-1', 'account_type' => 'brand', 'professional_type' => 'brand']);
    expect($this->policy->managePaymentMethod($brand, $brand))->toBeTrue();
});

it('managePaymentMethod: brand denied for different brand', function () {
    $brand = (new Professional)->forceFill(['id' => 'b-1', 'account_type' => 'brand', 'professional_type' => 'brand']);
    $other = (new Professional)->forceFill(['id' => 'b-2', 'account_type' => 'brand', 'professional_type' => 'brand']);
    expect($this->policy->managePaymentMethod($brand, $other))->toBeFalse();
});

it('managePaymentMethod: partner denied', function () {
    $partner = (new Professional)->forceFill(['id' => 'p-1', 'account_type' => 'partner', 'professional_type' => 'affiliate']);
    expect($this->policy->managePaymentMethod($partner, $partner))->toBeFalse();
});

it('managePaymentMethod: individual denied', function () {
    $individual = (new Professional)->forceFill(['id' => 'i-1', 'account_type' => 'individual', 'professional_type' => 'professional']);
    expect($this->policy->managePaymentMethod($individual, $individual))->toBeFalse();
});

// ─── manageWallet ──────────────────────────────────────────────────────────────

it('manageWallet: brand allowed for self', function () {
    $brand = (new Professional)->forceFill(['id' => 'b-1', 'account_type' => 'brand', 'professional_type' => 'brand']);
    expect($this->policy->manageWallet($brand, $brand))->toBeTrue();
});

it('manageWallet: brand denied for different brand', function () {
    $brand = (new Professional)->forceFill(['id' => 'b-1', 'account_type' => 'brand', 'professional_type' => 'brand']);
    $other = (new Professional)->forceFill(['id' => 'b-2', 'account_type' => 'brand', 'professional_type' => 'brand']);
    expect($this->policy->manageWallet($brand, $other))->toBeFalse();
});

it('manageWallet: partner denied', function () {
    $partner = (new Professional)->forceFill(['id' => 'p-1', 'account_type' => 'partner', 'professional_type' => 'affiliate']);
    expect($this->policy->manageWallet($partner, $partner))->toBeFalse();
});

it('manageWallet: individual denied', function () {
    $individual = (new Professional)->forceFill(['id' => 'i-1', 'account_type' => 'individual', 'professional_type' => 'professional']);
    expect($this->policy->manageWallet($individual, $individual))->toBeFalse();
});
