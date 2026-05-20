<?php

// Plan §28.3 / §9 capability matrix tests.
//
// Asserts each (account_type × capability) cell of the matrix is wired right.
// Conditional flags that depend on data not yet present in the schema
// (shows_ex_partner_panel, receives_payout_settlement_notifications for
// individuals) are tested against their current behavior — once §28.16 lands
// the has_historical_partner_links column + relationship, those rows get
// updated to exercise the conditional branches.

use App\Enums\AccountType;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Http\Resources\ProfessionalResource;
use App\Models\Core\Professional\Professional;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Accounts\AccountCapabilitySet;
use Illuminate\Http\Request;

function makeProForCapabilities(string $accountType): Professional
{
    return new Professional(['account_type' => $accountType]);
}

beforeEach(fn () => AccountCapabilities::flushCache());

describe('AccountCapabilities — brand', function () {
    beforeEach(function () {
        $this->caps = AccountCapabilities::for(makeProForCapabilities('brand'));
    });

    it('flags brand-only commerce + dashboard surfaces', function () {
        expect($this->caps->requires_stripe_connect)->toBeTrue();
        expect($this->caps->requires_tax_info)->toBeTrue();
        expect($this->caps->requires_payout_schedule)->toBeTrue();
        expect($this->caps->shows_shop_section)->toBeTrue();
        expect($this->caps->shows_commissions_dashboard)->toBeTrue();
        expect($this->caps->shows_orders_dashboard)->toBeTrue();
        expect($this->caps->shows_affiliates_dashboard)->toBeTrue();
        expect($this->caps->can_edit_design)->toBeTrue();
    });

    it('subscribes to commerce + payout notifications but not invites or brand-status', function () {
        expect($this->caps->receives_order_notifications)->toBeTrue();
        expect($this->caps->receives_payout_notifications)->toBeTrue();
        expect($this->caps->receives_payout_settlement_notifications)->toBeTrue();
        expect($this->caps->receives_commission_notifications)->toBeTrue();
        expect($this->caps->receives_brand_status_notifications)->toBeFalse();
        expect($this->caps->receives_invite_notifications)->toBeFalse();
    });

    it('cannot be linked to another brand and is not an ex-partner', function () {
        expect($this->caps->can_have_brand_link)->toBeFalse();
        expect($this->caps->shows_ex_partner_panel)->toBeFalse();
    });

    it('routes via worker_kv_type=brand and gets the full notification category set', function () {
        expect($this->caps->worker_kv_type)->toBe('brand');
        expect($this->caps->notification_categories)->toBe('full');
    });
});

describe('AccountCapabilities — partner', function () {
    beforeEach(function () {
        $this->caps = AccountCapabilities::for(makeProForCapabilities('partner'));
    });

    it('keeps the brand commerce stack but no affiliates-of-affiliates view', function () {
        expect($this->caps->requires_stripe_connect)->toBeTrue();
        expect($this->caps->requires_tax_info)->toBeTrue();
        expect($this->caps->requires_payout_schedule)->toBeTrue();
        expect($this->caps->shows_shop_section)->toBeTrue();
        expect($this->caps->shows_commissions_dashboard)->toBeTrue();
        expect($this->caps->shows_orders_dashboard)->toBeTrue();
        expect($this->caps->shows_affiliates_dashboard)->toBeFalse();
        expect($this->caps->shows_ex_partner_panel)->toBeFalse();
    });

    it('subscribes to the full commerce + payout notification stack', function () {
        expect($this->caps->receives_order_notifications)->toBeTrue();
        expect($this->caps->receives_payout_notifications)->toBeTrue();
        expect($this->caps->receives_payout_settlement_notifications)->toBeTrue();
        expect($this->caps->receives_commission_notifications)->toBeTrue();
        expect($this->caps->notification_categories)->toBe('full');
    });

    it('inherits brand design (no per-affiliate overrides, plan rule #6)', function () {
        expect($this->caps->can_edit_design)->toBeFalse();
    });

    it('can have a brand link and receives invites + brand status updates', function () {
        expect($this->caps->can_have_brand_link)->toBeTrue();
        expect($this->caps->receives_brand_status_notifications)->toBeTrue();
        expect($this->caps->receives_invite_notifications)->toBeTrue();
    });

    it('routes via worker_kv_type=affiliate', function () {
        expect($this->caps->worker_kv_type)->toBe('affiliate');
    });
});

describe('AccountCapabilities — individual', function () {
    beforeEach(function () {
        $this->caps = AccountCapabilities::for(makeProForCapabilities('individual'));
    });

    it('skips every commerce + onboarding requirement', function () {
        expect($this->caps->requires_stripe_connect)->toBeFalse();
        expect($this->caps->requires_tax_info)->toBeFalse();
        expect($this->caps->requires_payout_schedule)->toBeFalse();
        expect($this->caps->shows_shop_section)->toBeFalse();
        expect($this->caps->shows_commissions_dashboard)->toBeFalse();
        expect($this->caps->shows_orders_dashboard)->toBeFalse();
        expect($this->caps->shows_affiliates_dashboard)->toBeFalse();
    });

    it('keeps its own design editor and can connect to a brand', function () {
        expect($this->caps->can_edit_design)->toBeTrue();
        expect($this->caps->can_have_brand_link)->toBeTrue();
    });

    it('only receives the filtered notification subset', function () {
        expect($this->caps->receives_invite_notifications)->toBeTrue();
        expect($this->caps->receives_order_notifications)->toBeFalse();
        expect($this->caps->receives_payout_notifications)->toBeFalse();
        expect($this->caps->receives_commission_notifications)->toBeFalse();
        expect($this->caps->receives_brand_status_notifications)->toBeFalse();
        expect($this->caps->notification_categories)->toBe('profile,platform,invites');
    });

    it('treats no-history individuals as not-an-ex-partner until §28.16 wires the denorm column', function () {
        // Until §28.16 lands, individuals are treated as no-history. SCALE-1
        // swaps in has_historical_partner_links once the column exists.
        expect($this->caps->shows_ex_partner_panel)->toBeFalse();
        expect($this->caps->receives_payout_settlement_notifications)->toBeFalse();
    });

    it('routes via worker_kv_type=individual', function () {
        expect($this->caps->worker_kv_type)->toBe('individual');
    });
});

describe('AccountCapabilities — fallback', function () {
    it('treats a null account_type as individual during the dual-write window', function () {
        $pro = new Professional(['account_type' => null]);

        $caps = AccountCapabilities::for($pro);

        expect($caps)->toBeInstanceOf(AccountCapabilitySet::class);
        expect($caps->worker_kv_type)->toBe('individual');
    });
});

describe('AccountCapabilities — conditional individual flags', function () {
    it('shows the ex-partner panel for an individual with historical partner links', function () {
        $pro = makeProForCapabilities('individual');
        $pro->has_historical_partner_links = true;

        expect(AccountCapabilities::for($pro)->shows_ex_partner_panel)->toBeTrue();
    });
});

describe('AccountCapabilities — per-instance memoization', function () {
    it('returns the same memoized set for repeated lookups on one Professional', function () {
        $pro = makeProForCapabilities('individual');

        expect(AccountCapabilities::for($pro))->toBe(AccountCapabilities::for($pro));
    });

    it('keeps separate memo entries per Professional instance', function () {
        $brand = makeProForCapabilities('brand');
        $individual = makeProForCapabilities('individual');

        expect(AccountCapabilities::for($brand)->worker_kv_type)->toBe('brand');
        expect(AccountCapabilities::for($individual)->worker_kv_type)->toBe('individual');
    });

    it('flushCache() drops the memo so an in-place mutated Professional rebuilds (audit ACCT-2)', function () {
        $pro = makeProForCapabilities('individual');
        $stale = AccountCapabilities::for($pro);
        expect($stale->worker_kv_type)->toBe('individual');

        // Mutate in place — same object identity, so the WeakMap memo survives.
        $pro->account_type = AccountType::Partner;
        expect(AccountCapabilities::for($pro))->toBe($stale);

        // flushCache forces a rebuild against the new account_type.
        AccountCapabilities::flushCache();
        $rebuilt = AccountCapabilities::for($pro);

        expect($rebuilt)->not->toBe($stale);
        expect($rebuilt->worker_kv_type)->toBe('affiliate');
    });
});

describe('ProfessionalDashboardResource — §28.8a stripe_connect_status gate', function () {
    it('includes stripe_connect_status for brand accounts', function () {
        $pro = new Professional([
            'account_type' => 'brand',
            'stripe_connect_status' => 'verified',
        ]);

        $payload = (new ProfessionalDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload)->toHaveKey('stripe_connect_status');
        expect($payload['stripe_connect_status'])->toBe('verified');
    });

    it('includes stripe_connect_status for partner accounts', function () {
        $pro = new Professional([
            'account_type' => 'partner',
            'stripe_connect_status' => 'pending',
        ]);

        $payload = (new ProfessionalDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload)->toHaveKey('stripe_connect_status');
        expect($payload['stripe_connect_status'])->toBe('pending');
    });

    it('omits stripe_connect_status entirely for individual accounts', function () {
        $pro = new Professional([
            'account_type' => 'individual',
            'stripe_connect_status' => 'whatever-was-here-legacy',
        ]);

        $payload = (new ProfessionalDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload)->not->toHaveKey('stripe_connect_status');
    });
});

describe('ProfessionalResource — §28.8a stripe_connect_status gate', function () {
    it('includes stripe_connect_status for partners', function () {
        $pro = new Professional([
            'account_type' => 'partner',
            'stripe_connect_status' => 'verified',
        ]);

        $payload = (new ProfessionalResource($pro))->resolve(Request::create('/'));

        expect($payload)->toHaveKey('stripe_connect_status');
    });

    it('omits stripe_connect_status for individuals', function () {
        $pro = new Professional([
            'account_type' => 'individual',
            'stripe_connect_status' => 'noise',
        ]);

        $payload = (new ProfessionalResource($pro))->resolve(Request::create('/'));

        expect($payload)->not->toHaveKey('stripe_connect_status');
    });
});
