<?php

// Plan §28.3 / §9 capability matrix tests — individual-only after standalone strip.

use App\Http\Resources\UserDashboardResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Accounts\AccountCapabilitySet;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Http\Request;

function makeProForCapabilities(string $accountType, ?string $sector = null): User
{
    // Unsaved model — never hits the DB, so the users_account_type_check CHECK
    // guard (tests/Pest.php) never fires here; validity relies on the enum cast.
    return new User(['account_type' => $accountType, 'sector' => $sector]);
}

beforeEach(fn () => AccountCapabilities::flushCache());

describe('AccountCapabilities — partna', function () {
    beforeEach(function () {
        $this->caps = AccountCapabilities::for(makeProForCapabilities('partna'));
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
        $pro = makeProForCapabilities('partna');

        expect(AccountCapabilities::for($pro))->toBe(AccountCapabilities::for($pro));
    });

    it('keeps separate memo entries per Professional instance', function () {
        $indA = makeProForCapabilities('partna');
        $indB = makeProForCapabilities('partna');

        expect(AccountCapabilities::for($indA)->worker_kv_type)->toBe('individual');
        expect(AccountCapabilities::for($indB)->worker_kv_type)->toBe('individual');
    });

    it('flushCache() drops the memo so the next call rebuilds', function () {
        $pro = makeProForCapabilities('partna');
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
});

describe('AccountCapabilities — lifestyle pages (standard only)', function () {
    it('grants the lifestyle/creator pages to standard (partna) accounts', function () {
        expect(AccountCapabilities::for(makeProForCapabilities('partna'))->can_use_lifestyle_pages)->toBeTrue();
    });

    it('withholds the lifestyle/creator pages from Business accounts', function () {
        expect(AccountCapabilities::for(makeProForCapabilities('business'))->can_use_lifestyle_pages)->toBeFalse();
    });
});

describe('AccountCapabilities — sector-derived (2026-07-15 industry/sector gating)', function () {
    // Contract is LAW (docs/superpowers/plans/2026-07-15-industry-sector-gating.md),
    // amended 2026-09-01 in the can_use_menu clause ONLY:
    //   can_use_menu            = food                    (was: business && food)
    //   can_use_reservations    = business ? food : true
    //   can_use_booking         = business ? !food : true
    //   can_use_online_ordering = business && food
    // The booking half stays type-branched — a venue books tables XOR
    // appointments, a person does both. Menu no longer is: a cafe is a food
    // account whatever enum it was filed under, and reading account_type there
    // is what stripped the Menu page off ollies while its 105 ingested menu
    // items shipped in the payload. A null sector still reads as not-food on
    // every clause (same row as an explicit non-food sector), so an account
    // whose industry is unknown gains nothing.
    it('gives the full matrix for every account-type × sector combination', function (
        string $accountType,
        ?string $sector,
        bool $menu,
        bool $reservations,
        bool $booking,
        bool $onlineOrdering,
    ) {
        $caps = AccountCapabilities::for(makeProForCapabilities($accountType, $sector));

        expect($caps->can_use_menu)->toBe($menu);
        expect($caps->can_use_reservations)->toBe($reservations);
        expect($caps->can_use_booking)->toBe($booking);
        expect($caps->can_use_online_ordering)->toBe($onlineOrdering);
    })->with([
        'partna × food (restaurant)' => ['partna', 'restaurant', true, true, true, false],
        'partna × non-food (barber)' => ['partna', 'barber', false, true, true, false],
        'partna × null sector' => ['partna', null, false, true, true, false],
        'business × food (restaurant)' => ['business', 'restaurant', true, true, false, true],
        'business × non-food (barber)' => ['business', 'barber', false, false, true, false],
        'business × null sector (defaults not-food)' => ['business', null, false, false, true, false],
    ]);

    it('isFood is false for every non-Food & Drink sector, true for exactly the Food & Drink group', function () {
        foreach (SectorTaxonomy::FOOD_SECTORS as $foodSlug) {
            expect(SectorTaxonomy::isFood($foodSlug))->toBeTrue();
        }
        expect(SectorTaxonomy::isFood('barber'))->toBeFalse();
        expect(SectorTaxonomy::isFood('photographer'))->toBeFalse();
        expect(SectorTaxonomy::isFood(null))->toBeFalse();
        expect(SectorTaxonomy::isFood('not-a-real-sector'))->toBeFalse();
    });
});

describe('UserDashboardResource — sector + capabilities (2026-07-15)', function () {
    it('emits sector, sector_source, and camelCase capabilities for a food business', function () {
        // sector_source is deliberately NOT fillable (service-written only) —
        // forceFill it directly, same as SectorController/IdentitySync do.
        $pro = (new User(['account_type' => 'business', 'sector' => 'restaurant']))
            ->forceFill(['sector_source' => 'manual']);

        $payload = (new UserDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload['sector'])->toBe('restaurant');
        expect($payload['sector_source'])->toBe('manual');
        // Exact match on purpose: this is the dashboard's capability contract,
        // and a key silently appearing or vanishing is exactly what it guards.
        expect($payload['capabilities'])->toBe([
            'canUseMenu' => true,
            'canUseReservations' => true,
            'canUseBooking' => false,
            'canUseOnlineOrdering' => true,
            'canUseMultipageSite' => true,
            'canUseLifestylePages' => false,
            'canBookStorewide' => true,
            'canEditDesign' => true,
            'canCurateIdentity' => true,
            'canSubmitFeedback' => true,
            'canBeReported' => false,
            'canAutosyncScrapedConnections' => true,
            'googleBusinessFullSync' => true,
            'googleBusinessSetsDisplayName' => true,
            'receiveModerationNotifications' => false,
            'workplaceBrandIsSiteIdentity' => true,
        ]);
    });

    it('emits null sector/sector_source and the partna capability row when nothing is set', function () {
        $pro = new User(['account_type' => 'partna']);

        $payload = (new UserDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload['sector'])->toBeNull();
        expect($payload['sector_source'])->toBeNull();
        expect($payload['capabilities'])->toBe([
            'canUseMenu' => false,
            'canUseReservations' => true,
            'canUseBooking' => true,
            'canUseOnlineOrdering' => false,
            'canUseMultipageSite' => false,
            'canUseLifestylePages' => true,
            'canBookStorewide' => false,
            'canEditDesign' => true,
            'canCurateIdentity' => true,
            'canSubmitFeedback' => true,
            'canBeReported' => false,
            'canAutosyncScrapedConnections' => true,
            'googleBusinessFullSync' => false,
            'googleBusinessSetsDisplayName' => false,
            'receiveModerationNotifications' => false,
            'workplaceBrandIsSiteIdentity' => false,
        ]);
    });
});

describe('AccountCapabilities — auto-sync consent gate (removed 2026-07-25, now always true)', function () {
    it('grants auto-sync consent to an unclaimed (provisional) account (gate removed)', function () {
        $pro = (new User(['account_type' => 'partna']))->forceFill(['status' => 'unclaimed']);

        expect(AccountCapabilities::for($pro)->can_autosync_scraped_connections)->toBeTrue();
    });

    it('grants auto-sync consent to an active (claimed) account', function () {
        $pro = (new User(['account_type' => 'partna']))->forceFill(['status' => 'active']);

        expect(AccountCapabilities::for($pro)->can_autosync_scraped_connections)->toBeTrue();
    });

    it('grants auto-sync consent to any other non-unclaimed status (spot-check: suspended)', function () {
        $pro = (new User(['account_type' => 'partna']))->forceFill(['status' => 'suspended']);

        expect(AccountCapabilities::for($pro)->can_autosync_scraped_connections)->toBeTrue();
    });
});

describe('UserDashboardResource — stripe_connect_status absent for standard accounts', function () {
    it('omits stripe_connect_status entirely for standard accounts', function () {
        $pro = new User([
            'account_type' => 'partna',
        ]);

        $payload = (new UserDashboardResource($pro))->resolve(Request::create('/'));

        expect($payload)->not->toHaveKey('stripe_connect_status');
    });
});
