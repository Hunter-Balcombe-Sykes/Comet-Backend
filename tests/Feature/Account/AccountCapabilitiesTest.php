<?php

// Plan §28.3 / §9 capability matrix tests — individual-only after standalone strip.

use App\Enums\AccountType;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Http\Resources\ProfessionalResource;
use App\Models\Core\Professional\User;
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

describe('ProfessionalDashboardResource — stripe_connect_status absent for individuals', function () {
    it('omits stripe_connect_status entirely for individual accounts', function () {
        $pro = new User([
            'account_type' => 'individual',
        ]);

        $payload = (new ProfessionalDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload)->not->toHaveKey('stripe_connect_status');
    });
});

describe('ProfessionalResource — stripe_connect_status absent for individuals', function () {
    it('omits stripe_connect_status for individuals', function () {
        $pro = new User([
            'account_type' => 'individual',
        ]);

        $payload = (new ProfessionalResource($pro))->resolve(Request::create('/'));

        expect($payload)->not->toHaveKey('stripe_connect_status');
    });
});
