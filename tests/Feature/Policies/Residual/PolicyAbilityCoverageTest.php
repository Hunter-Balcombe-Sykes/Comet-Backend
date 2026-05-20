<?php

// §28.17 TEST-2 residual — ability coverage for the seven policies that the
// §28.11 capability-gating sweep didn't touch. PolicyCoverageTest enforces
// that each policy is registered; this file proves each registered policy
// actually allows/denies the right actors.
//
// Coverage model: at least one allow + one deny per public ability per policy.
// Edge cases that materially change behaviour (pending_deletion, audit-log
// immutability, no-self-edit on staff) get dedicated tests.

use App\Models\Billing\Subscription;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\FeatureFlag;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Staff\PartnaStaff;
use App\Policies\AffiliateProductPolicy;
use App\Policies\BrandResourcePolicy;
use App\Policies\FeatureFlagPolicy;
use App\Policies\GdprPolicy;
use App\Policies\PartnaStaffPolicy;
use App\Policies\ProfessionalSelfPolicy;
use App\Policies\SubscriptionPolicy;

function makePolicyTestPro(string $id, string $accountType = 'individual', ?string $status = 'active'): Professional
{
    return (new Professional)->forceFill([
        'id' => $id,
        'account_type' => $accountType,
        'professional_type' => $accountType === 'brand' ? 'brand' : 'affiliate',
        'status' => $status,
    ]);
}

// ── AffiliateProductPolicy ───────────────────────────────────────────────────

describe('AffiliateProductPolicy', function () {
    beforeEach(fn () => $this->policy = new AffiliateProductPolicy);

    it('view: allows the affiliate who selected', function () {
        $actor = makePolicyTestPro('a-1');
        $sel = (new AffiliateProductSelection)->forceFill(['affiliate_professional_id' => 'a-1']);
        expect($this->policy->view($actor, $sel))->toBeTrue();
    });

    it('view: denies an unrelated actor (404, not 403)', function () {
        $actor = makePolicyTestPro('z-1');
        $sel = (new AffiliateProductSelection)->forceFill(['affiliate_professional_id' => 'a-1', 'brand_professional_id' => 'b-1']);
        $result = $this->policy->view($actor, $sel);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(404);
    });

    it('create: only the owning affiliate may create', function () {
        $actor = makePolicyTestPro('a-1');
        $skel = (new AffiliateProductSelection)->forceFill(['affiliate_professional_id' => 'a-1']);
        expect($this->policy->create($actor, $skel))->toBeTrue();

        $other = makePolicyTestPro('z-1');
        expect($this->policy->create($other, $skel))->toBeFalse();
    });

    it('update: pending_deletion actor is blocked even when ownership matches', function () {
        $actor = makePolicyTestPro('a-1', 'individual', 'pending_deletion');
        $sel = (new AffiliateProductSelection)->forceFill(['affiliate_professional_id' => 'a-1']);
        $result = $this->policy->update($actor, $sel);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(423);
    });
});

// ── BrandResourcePolicy ──────────────────────────────────────────────────────

describe('BrandResourcePolicy', function () {
    beforeEach(fn () => $this->policy = new BrandResourcePolicy);

    it('view: owner allowed, non-owner gets 404', function () {
        $owner = makePolicyTestPro('b-1', 'brand');
        $other = makePolicyTestPro('b-2', 'brand');
        $resource = (new BrandStoreSettings)->forceFill(['professional_id' => 'b-1']);
        expect($this->policy->view($owner, $resource))->toBeTrue();
        expect($this->policy->view($other, $resource))->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($this->policy->view($other, $resource)->status())->toBe(404);
    });

    it('update: pending_deletion blocked with 423', function () {
        $owner = makePolicyTestPro('b-1', 'brand', 'pending_deletion');
        $resource = (new BrandStoreSettings)->forceFill(['professional_id' => 'b-1']);
        $result = $this->policy->update($owner, $resource);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(423);
    });

    it('create: a skeleton with the actor as owner is allowed', function () {
        $owner = makePolicyTestPro('b-1', 'brand');
        $skel = (new BrandStoreSettings)->forceFill(['professional_id' => 'b-1']);
        expect($this->policy->create($owner, $skel))->toBeTrue();
    });
});

// ── GdprPolicy ───────────────────────────────────────────────────────────────

describe('GdprPolicy', function () {
    beforeEach(fn () => $this->policy = new GdprPolicy);

    it('view: owner allowed', function () {
        $actor = makePolicyTestPro('a-1');
        $resource = (new GdprRequest)->forceFill(['professional_id' => 'a-1']);
        expect($this->policy->view($actor, $resource))->toBeTrue();
    });

    it('view: non-owner gets 404 (no existence leak)', function () {
        $actor = makePolicyTestPro('z-1');
        $resource = (new GdprRequest)->forceFill(['professional_id' => 'a-1']);
        $result = $this->policy->view($actor, $resource);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(404);
    });

    it('view: pending_deletion actor can still view their OWN GDPR records (deletion in progress)', function () {
        $actor = makePolicyTestPro('a-1', 'individual', 'pending_deletion');
        $resource = (new GdprRequest)->forceFill(['professional_id' => 'a-1']);
        expect($this->policy->view($actor, $resource))->toBeTrue();
    });
});

// ── ProfessionalSelfPolicy ───────────────────────────────────────────────────

describe('ProfessionalSelfPolicy', function () {
    beforeEach(fn () => $this->policy = new ProfessionalSelfPolicy);

    it('view: actor can view their own Professional record', function () {
        $actor = makePolicyTestPro('a-1');
        expect($this->policy->view($actor, $actor))->toBeTrue();
    });

    it('view: actor cannot view another Professional', function () {
        $actor = makePolicyTestPro('a-1');
        $other = makePolicyTestPro('a-2');
        $result = $this->policy->view($actor, $other);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(404);
    });

    it('update: audit-log models are immutable even for the owner', function () {
        $actor = makePolicyTestPro('a-1');
        $audit = (new ProfessionalDeletionAuditEntry)->forceFill(['professional_id' => 'a-1']);
        $result = $this->policy->update($actor, $audit);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(404);
    });
});

// ── SubscriptionPolicy ───────────────────────────────────────────────────────

describe('SubscriptionPolicy', function () {
    beforeEach(fn () => $this->policy = new SubscriptionPolicy);

    it('view: owner allowed, non-owner 404', function () {
        $actor = makePolicyTestPro('a-1');
        $sub = (new Subscription)->forceFill(['professional_id' => 'a-1']);
        expect($this->policy->view($actor, $sub))->toBeTrue();

        $other = makePolicyTestPro('z-1');
        $result = $this->policy->view($other, $sub);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(404);
    });

    it('update: pending_deletion owner cannot mutate (423) even though view is still allowed', function () {
        $actor = makePolicyTestPro('a-1', 'individual', 'pending_deletion');
        $sub = (new Subscription)->forceFill(['professional_id' => 'a-1']);
        expect($this->policy->view($actor, $sub))->toBeTrue();
        $result = $this->policy->update($actor, $sub);
        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class)
            ->and($result->status())->toBe(423);
    });
});

// ── PartnaStaffPolicy ────────────────────────────────────────────────────────

describe('PartnaStaffPolicy', function () {
    beforeEach(fn () => $this->policy = new PartnaStaffPolicy);

    it('update: admin can update another staff record', function () {
        $admin = (new PartnaStaff)->forceFill(['id' => 's-admin', 'role' => 'admin']);
        $target = (new PartnaStaff)->forceFill(['id' => 's-support', 'role' => 'support']);
        expect($this->policy->update($admin, $target))->toBeTrue();
    });

    it('update: admin CANNOT update their own staff record (no self-edit)', function () {
        $admin = (new PartnaStaff)->forceFill(['id' => 's-admin', 'role' => 'admin']);
        expect($this->policy->update($admin, $admin))->toBeFalse();
    });

    it('update: support cannot update anyone', function () {
        $support = (new PartnaStaff)->forceFill(['id' => 's-support', 'role' => 'support']);
        $target = (new PartnaStaff)->forceFill(['id' => 's-other', 'role' => 'support']);
        expect($this->policy->update($support, $target))->toBeFalse();
    });

    it('delete: admin cannot delete themselves', function () {
        $admin = (new PartnaStaff)->forceFill(['id' => 's-admin', 'role' => 'admin']);
        expect($this->policy->delete($admin, $admin))->toBeFalse();
    });
});

// ── FeatureFlagPolicy ────────────────────────────────────────────────────────

describe('FeatureFlagPolicy', function () {
    beforeEach(fn () => $this->policy = new FeatureFlagPolicy);

    // Deny-all by design: real auth is the EnsurePartnaStaff middleware. The
    // policy exists to satisfy PolicyCoverageTest AND to make Gate::forUser($pro)
    // safe in case any future route accidentally exposes a flag endpoint
    // outside the staff middleware group.

    it('viewAny: denies any Professional actor', function () {
        $pro = makePolicyTestPro('a-1');
        expect($this->policy->viewAny($pro))->toBeFalse();
    });

    it('view: denies any Professional actor on a real FeatureFlag', function () {
        $pro = makePolicyTestPro('a-1');
        $flag = (new FeatureFlag)->forceFill(['id' => 'f-1', 'key' => 'some_flag']);
        expect($this->policy->view($pro, $flag))->toBeFalse();
    });

    it('manage: denies any Professional actor (with or without a resource)', function () {
        $pro = makePolicyTestPro('a-1');
        expect($this->policy->manage($pro))->toBeFalse();
        $flag = (new FeatureFlag)->forceFill(['id' => 'f-1', 'key' => 'some_flag']);
        expect($this->policy->manage($pro, $flag))->toBeFalse();
    });
});
