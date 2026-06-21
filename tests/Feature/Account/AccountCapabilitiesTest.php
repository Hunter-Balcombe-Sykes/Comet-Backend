<?php

// Plan §28.3 / §9 capability matrix tests — individual-only after standalone strip.

use App\Http\Resources\UserDashboardResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Accounts\AccountCapabilitySet;
use Illuminate\Http\Request;

function makeProForCapabilities(string $accountType): User
{
    return new User(['account_type' => $accountType]);
}

beforeEach(fn () => AccountCapabilities::flushCache());

describe('AccountCapabilities — individual', function () {
    beforeEach(function () {
        $this->caps = AccountCapabilities::for(makeProForCapabilities('individual'));
    });

    it('keeps its own design editor', function () {
        expect($this->caps->can_edit_design)->toBeTrue();
    });

    it('only receives the profile/platform notification subset', function () {
        expect($this->caps->notification_categories)->toBe('profile,platform');
    });

    it('routes via worker_kv_type=individual', function () {
        expect($this->caps->worker_kv_type)->toBe('individual');
    });
});

describe('AccountCapabilities — fallback', function () {
    it('treats a null account_type as individual during the dual-write window', function () {
        $pro = new User(['account_type' => null]);

        $caps = AccountCapabilities::for($pro);

        expect($caps)->toBeInstanceOf(AccountCapabilitySet::class);
        expect($caps->worker_kv_type)->toBe('individual');
    });
});

describe('AccountCapabilities — per-instance memoization', function () {
    it('returns the same memoized set for repeated lookups on one Professional', function () {
        $pro = makeProForCapabilities('individual');

        expect(AccountCapabilities::for($pro))->toBe(AccountCapabilities::for($pro));
    });

    it('keeps separate memo entries per Professional instance', function () {
        $indA = makeProForCapabilities('individual');
        $indB = makeProForCapabilities('individual');

        expect(AccountCapabilities::for($indA)->worker_kv_type)->toBe('individual');
        expect(AccountCapabilities::for($indB)->worker_kv_type)->toBe('individual');
    });

    it('flushCache() drops the memo so the next call rebuilds', function () {
        $pro = makeProForCapabilities('individual');
        $stale = AccountCapabilities::for($pro);
        expect($stale->worker_kv_type)->toBe('individual');

        AccountCapabilities::flushCache();
        $rebuilt = AccountCapabilities::for($pro);

        // New object after flush.
        expect($rebuilt)->not->toBe($stale);
        expect($rebuilt->worker_kv_type)->toBe('individual');
    });
});

describe('AccountCapabilities — storewide booking (Business Partna)', function () {
    it('grants storewide booking to Business accounts', function () {
        expect(AccountCapabilities::for(makeProForCapabilities('business'))->can_book_storewide)->toBeTrue();
    });

    it('withholds storewide booking from standard (partna) accounts', function () {
        expect(AccountCapabilities::for(makeProForCapabilities('partna'))->can_book_storewide)->toBeFalse();
    });

    it('withholds storewide booking from legacy individual rows', function () {
        expect(AccountCapabilities::for(makeProForCapabilities('individual'))->can_book_storewide)->toBeFalse();
    });
});

describe('AccountCapabilities — Google Business (Business Partna)', function () {
    it('gives Business accounts the full Google Business auto-sync + name adoption', function () {
        $caps = AccountCapabilities::for(makeProForCapabilities('business'));
        expect($caps->google_business_full_sync)->toBeTrue();
        expect($caps->google_business_sets_display_name)->toBeTrue();
    });

    it('limits standard (partna) accounts to booking-only sync and no name adoption', function () {
        $caps = AccountCapabilities::for(makeProForCapabilities('partna'));
        expect($caps->google_business_full_sync)->toBeFalse();
        expect($caps->google_business_sets_display_name)->toBeFalse();
    });

    it('treats legacy individual rows like standard accounts', function () {
        $caps = AccountCapabilities::for(makeProForCapabilities('individual'));
        expect($caps->google_business_full_sync)->toBeFalse();
        expect($caps->google_business_sets_display_name)->toBeFalse();
    });
});

describe('UserDashboardResource — stripe_connect_status absent for individuals', function () {
    it('omits stripe_connect_status entirely for individual accounts', function () {
        $pro = new User([
            'account_type' => 'individual',
        ]);

        $payload = (new UserDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload)->not->toHaveKey('stripe_connect_status');
    });
});

describe('UserDashboardResource — stripe_connect_status absent for individuals', function () {
    it('omits stripe_connect_status for individuals', function () {
        $pro = new User([
            'account_type' => 'individual',
        ]);

        $payload = (new UserDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload)->not->toHaveKey('stripe_connect_status');
    });
});
