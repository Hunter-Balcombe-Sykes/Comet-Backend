<?php

use App\Catalog\CompiledCatalog;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\LinkValidity;
use App\Routing\PlacementPolicy;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\Rulepack;
use App\Routing\Verdict;
use Illuminate\Support\Str;

/**
 * B.4 — L1 is defined on the detector contract that already exists, NOT on a
 * parallel "host + path" grammar. The cases below are the ones that decide
 * whether the definition is the right one; each is a real URL shape.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

/** A restaurant business — the capability gate must not be what answers these. */
function l1GateUser(): User
{
    return createTenant('l1-gate-'.Str::random(6), ['account_type' => 'business', 'sector' => 'restaurant']);
}

function projectFor(string $url): Projection
{
    $iri = app(IriCanonicalizer::class)->canonicalize($url);

    return (new LinkProjector(Rulepack::fromCompiledCatalog()))->project($iri);
}

it('passes an OpenTable ccTLD link whose identity comes from the QUERY, not a path', function () {
    // 32 of OpenTable's 48 detectors carry an empty path_pattern with
    // query_requires ['restRef']. A path-only definition of L1 would refuse
    // every one of them — this is the case that rules that definition out.
    $projection = projectFor('https://www.opentable.com.au/r/?restRef=291533');

    expect($projection->matched())->toBeTrue()
        ->and(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);
});

it('marks a bare branded domain as WEAK — it identifies a brand, not an account', function () {
    $projection = projectFor('https://www.opentable.com.au/');

    // It still MATCHES (the host is unmistakably OpenTable). What it does not
    // do is name a restaurant, which is the whole of L1's question.
    expect(LinkValidity::l1($projection))->not->toBe(LinkValidity::PASS);
});

it('marks a marketing path on a connectable host as WEAK, not as an account', function () {
    $projection = projectFor('https://www.doordash.com/promo');

    expect(LinkValidity::l1($projection))->not->toBe(LinkValidity::PASS);
});

it('passes a link whose identifier the detector captured by name', function () {
    $projection = projectFor('https://www.instagram.com/partna.au/');

    expect($projection->identifier)->not->toBeNull()
        ->and(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);
});

it('reports NONE — not WEAK — for a URL nothing matched', function () {
    $projection = projectFor('https://example-nothing-matches-this.test/x');

    expect(LinkValidity::l1($projection))->toBe(LinkValidity::NONE);
});

it('never judges an identity the person gave us rather than one read off a URL', function () {
    // Google Business (place_id) and the sign-up Instagram handle must not be
    // able to fail closed: there is no path for them to have failed.
    foreach (CompiledCatalog::surfaces() as $surface) {
        if (in_array($surface['identifier_kind'], ['place_id', 'domain', 'handle'], true)) {
            expect(LinkValidity::applies($surface))->toBeFalse();
        }
    }
})->group('catalog');

it('applies to a connectable, active, url-kind surface', function () {
    $surface = CompiledCatalog::surface('doordash.order');

    expect($surface)->not->toBeNull()
        ->and(LinkValidity::applies($surface))->toBeTrue();
});

it('does not apply to a retired surface — a brand that stopped trading is not an invalid link', function () {
    $surface = CompiledCatalog::surface('menulog.order');

    expect($surface['lifecycle'])->toBe('retired')
        ->and(LinkValidity::applies($surface))->toBeFalse();
});

/**
 * The census this definition was written against, pinned so the pattern fleet
 * (C.2) has a number to move and so a regression that silently re-broadens the
 * detectors is visible. RAISE the ceiling only with a reason; the direction of
 * travel is down.
 */
it('pins how many connectable surfaces still have no rule beyond their domain', function () {
    $detectors = CompiledCatalog::detectors();
    $weakSurfaces = [];

    foreach (CompiledCatalog::surfaces() as $surface) {
        if (! LinkValidity::applies($surface)) {
            continue;
        }

        $specific = 0;
        foreach ($surface['detectors'] as $id) {
            if (isset($detectors[$id]) && LinkValidity::detectorIsSpecific($detectors[$id])) {
                $specific++;
            }
        }

        if ($specific === 0) {
            $weakSurfaces[] = $surface['key'];
        }
    }

    // 2026-09-03: 55 before the pattern fleet, 9 after. Every surface still in
    // this list connects a link it cannot name, refresh or verify — which is
    // the Uber Eats "no restaurant name" card, not a bug of its own.
    //
    // The nine that remain are remains BY DECISION, not by omission:
    //   easi.order        the brand folded into HungryPanda; its store host is
    //                     NXDOMAIN, so there is no format left to write.
    //   ovatu.book        no example URL that resolves could be found.
    //   eat_app.reserve   venue pages and country/city discovery pages share
    //                     one flat root namespace with no separating shape.
    //   oztix.tickets     its per-venue URLs are transactional booking
    //   ticketek.tickets  sessions and one-off events, not persistent pages.
    //   shortcuts.book    not yet researched.
    //   skool.community   detected by signal, not by a registrable host.
    //   squarespace/woocommerce.store  own-domain whitelabel: the customer's
    //                     store lives on their OWN domain, so it is the
    //                     storefront arm's job, not a detector's (decision 3).
    //
    // RAISE this only with a reason. The direction of travel is down.
    expect(count($weakSurfaces))->toBeLessThanOrEqual(9);
})->group('catalog');

/**
 * The fleet's own work, spot-checked against URLs its agents verified live.
 * A regex that compiles and a regex that matches the brand's real pages are
 * different claims, and only the second one is worth anything.
 */
it('passes the real page shapes the pattern fleet added', function () {
    $cases = [
        'https://www.quandoo.com.au/place/ricks-place-92706' => 'quandoo.reserve',
        'https://resy.com/cities/new-york-ny/venues/carbone' => 'resy.reserve',
        'https://www.doordash.com/store/mission-ranch-restaurant-mission-viejo-954502' => 'doordash.order',
        'https://deliveroo.co.uk/menu/london/enfield/the-meeting-bar-and-restaurant' => 'deliveroo.order',
        'https://www.thefork.com/restaurant/confraria-sushi-cascais-r718637' => 'thefork.reserve',
        'https://wolt.com/en/fin/helsinki/restaurant/noodle-story-freda' => 'wolt.order',
        'https://www.styleseat.com/m/some-stylist' => 'styleseat.book',
        'https://www.vagaro.com/pinktoesnailbar' => 'vagaro.book',
        'https://order.toasttab.com/online/toast-trattoria-omaha' => 'toast.order',
    ];

    foreach ($cases as $url => $surfaceKey) {
        $projection = projectFor($url);

        expect($projection->surfaceKey)->toBe($surfaceKey, $url)
            ->and(LinkValidity::l1($projection))->toBe(LinkValidity::PASS, $url)
            ->and($projection->identifier)->not->toBeEmpty($url);
    }
})->group('catalog');

/**
 * The other half, and the one that actually protects users: a brand's own
 * marketing and discovery pages must NOT read as somebody's account. These are
 * real URLs from the same brands.
 */
it('does not read a brand marketing or discovery page as an account', function () {
    $urls = [
        'https://www.doordash.com/food-delivery/new-york-city-ny-restaurants/',
        'https://deliveroo.co.uk/restaurants/london/the-city',
        'https://resy.com/cities/new-york-ny',
        'https://www.thefork.com/restaurants/new-york-c665788',
        'https://wolt.com/en/fin/helsinki',
        'https://www.vagaro.com/pricing',
        'https://www.zomato.com/bangalore/collections',
        'https://www.quandoo.com.au/en/hamburg',
    ];

    foreach ($urls as $url) {
        expect(LinkValidity::l1(projectFor($url)))->not->toBe(LinkValidity::PASS, $url);
    }
})->group('catalog');

/**
 * Gate 3 — the same question, asked at the ONE place every lane passes through.
 *
 * These pin the owner's rule ("never let it be saved if it fails as a
 * connectable + active platform") at the routing policy rather than in any one
 * lane's code, which is what makes paste, harvest and the setup dialog agree
 * with the manual connect strategy instead of each deciding for itself.
 */
it('keeps a brand-domain match as a LINK rather than claiming it is an account', function () {
    $placement = (new PlacementPolicy)->decide(
        projectFor('https://www.doordash.com/promo'),
        RoutingContext::forUser(l1GateUser(), 'paste'),
    );

    // Note, not Reject: the person still gets their link. What they do not get
    // is an ordering CTA pointed at a marketing page, a connection whose
    // identifier is the whole URL, or a card with no restaurant name on it —
    // the three symptoms of the single defect this gate closes.
    expect($placement->verdict)->toBe(Verdict::Note)
        ->and($placement->verdict->writesIntent())->toBeFalse()
        ->and($placement->identifier)->toBeNull()
        ->and($placement->blockReason)->toBe('invalid_identifier');
});

it('still routes a real store page on the same brand', function () {
    $placement = (new PlacementPolicy)->decide(
        projectFor('https://www.doordash.com/store/souva-king-wollongong-23852127/'),
        RoutingContext::forUser(l1GateUser(), 'paste'),
    );

    // Choose, not Place: a real DoorDash deep link scores below the ordering
    // class's auto threshold, so it is a suggestion. That is the pre-existing
    // confidence behaviour and not what this gate decides — what matters here
    // is that it writes an intent at all, which the branch above does not.
    expect(LinkValidity::l1(projectFor('https://www.doordash.com/store/souva-king-wollongong-23852127/')))
        ->toBe(LinkValidity::PASS)
        ->and($placement->verdict->writesIntent())->toBeTrue()
        ->and($placement->identifier)->not->toBeNull();
});

it('leaves a surface we have no shape on file for exactly as it was', function () {
    // easi.order is one of the nine connectable surfaces with no specific
    // detector anywhere. We cannot say a link there is wrong, so we do not:
    // it routes as before and is checked at accept time by the L2 lane.
    expect(LinkValidity::hasShape('easi.order'))->toBeFalse();

    $projection = projectFor('https://www.easi.com.au/whatever');
    expect(LinkValidity::l1($projection))->toBe(LinkValidity::WEAK);

    // Gate 3 did not fire, and that absence is the whole assertion: a surface
    // we hold no shape for is one we have no standing to refuse, so the link
    // goes on to be asked about like any other. It arrives as a Choose with NO
    // block reason — under the confidence system this same link was stopped by
    // the floor and reasoned 'needs_confirmation', a refusal we could not actually
    // justify.
    $placement = (new PlacementPolicy)->decide($projection, RoutingContext::forUser(l1GateUser(), 'paste'));
    expect($placement->blockReason)->toBeNull()
        ->and($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('suggest');
});
