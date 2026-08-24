<?php

// Issue 3 (st-ali-bali signup, 2026-08-24). The business's real OpenTable
// booking link projected CORRECTLY to opentable.reserve and was then thrown
// away: confidence 59, margin 0, Verdict::Note — and Note writes no intent
// (Verdict::writesIntent()), so the link landed in the links pool as a plain
// card with no way to promote it to the reservations connection it plainly is.
//
// Two independent defects combined, and both are pinned here:
//   a) Opentable.php declares a `rid` query rule AND a `restRef` query rule per
//      TLD. The live URL carries both params (the query match in
//      LinkProjector::score() uses strcasecmp, so the catalog's `restRef`
//      matches the URL's lowercase `restref`), both rules resolve to the SAME
//      surface, and both score identically — so margin, measured as the gap to
//      the plain runner-up, read two rules AGREEING as maximum ambiguity.
//   b) A path match scores +35 while a required query param scores +15, even
//      though `?rid=291533` names a restaurant exactly as precisely as
//      `/restaurant/profile/291533` — and Opentable.php declares both shapes
//      at the same EvidenceStrength::DeepLinkWithSlug.
//
// DB-free by construction: the projector runs off the compiled catalog.

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\Projection;
use App\Routing\PublicSuffixList;
use App\Routing\RoutingPolicy;
use App\Routing\Rulepack;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** The live URL from the signup trace, minus the utm_* params the canonicaliser strips. */
function openTableBookingUrl(): string
{
    return 'https://www.opentable.com.au/booking/experiences-availability?rid=291533&restref=291533&experienceId=782864';
}

/** The same restaurant, in the canonical path form the catalog templates. */
function openTableProfileUrl(): string
{
    return 'https://www.opentable.com.au/restaurant/profile/291533';
}

function projectRoutingUrl(string $url): Projection
{
    static $projector = null;
    static $canonicalizer = null;

    $projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $projector->project($canonicalizer->canonicalize($url));
}

it('keeps a real margin when several detectors describe the SAME surface', function () {
    $projection = projectRoutingUrl(openTableBookingUrl());

    expect($projection->surfaceKey)->toBe('opentable.reserve')
        ->and($projection->identifier)->toBe('291533')
        // Was 0: the `rid` and `restRef` rules tied, and the gap to the plain
        // runner-up is 0 when that runner-up is the same surface agreeing.
        ->and($projection->margin)->toBeGreaterThanOrEqual(RoutingPolicy::minMargin());
});

it('scores a query-captured identifier at parity with a path-captured one', function () {
    $query = projectRoutingUrl(openTableBookingUrl());
    $path = projectRoutingUrl(openTableProfileUrl());

    // Opentable.php declares BOTH shapes at EvidenceStrength::DeepLinkWithSlug
    // — the catalog's own statement that they are equally strong evidence. The
    // structural score used to contradict it: 40+15+4 = 59 against 40+35+4 = 79.
    expect($query->surfaceKey)->toBe('opentable.reserve')
        ->and($path->surfaceKey)->toBe('opentable.reserve')
        ->and($query->confidence)->toBe($path->confidence);
});

it('clears the reservations write threshold for an indirect harvest', function () {
    // The exact gate that dropped the live link. PlacementPolicy::decide()
    // subtracts the indirect penalty, then needs confidence >= suggest to
    // propose at all, and margin >= MIN_MARGIN for the harvest-maximisation
    // rule to auto-apply it. Below the suggest threshold it returns
    // Verdict::Note, which writes no intent — the links-pool card we saw.
    $projection = projectRoutingUrl(openTableBookingUrl());
    $effective = $projection->confidence - RoutingPolicy::indirectPenalty();

    expect($effective)->toBeGreaterThanOrEqual(RoutingPolicy::suggestThreshold('reservations'))
        ->and($projection->margin)->toBeGreaterThanOrEqual(RoutingPolicy::minMargin());
});
