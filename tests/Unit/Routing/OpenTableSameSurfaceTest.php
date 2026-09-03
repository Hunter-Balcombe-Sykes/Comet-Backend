<?php

// Issue 3 (st-ali-bali signup, 2026-08-24). The business's real OpenTable
// booking link projected CORRECTLY to opentable.reserve and was then thrown
// away: confidence 59, margin 0, Verdict::Note — and Note writes no intent
// (Verdict::writesIntent()), so the link landed in the links pool as a plain
// card with no way to promote it to the reservations connection it plainly is.
//
// Two independent defects combined, and both are pinned here:
//   a) Opentable.php declares a `rid` query rule AND a `restRef` query rule per
//      TLD. The live URL carries both params (the query match uses strcasecmp,
//      so the catalog's `restRef` matches the URL's lowercase `restref`), both
//      rules resolve to the SAME surface, and both matched identically — so
//      margin, measured as the gap to the plain runner-up, read two rules
//      AGREEING as maximum ambiguity.
//   b) A path match scored +35 while a required query param scored +15, even
//      though `?rid=291533` names a restaurant exactly as precisely as
//      `/restaurant/profile/291533` — and Opentable.php declares both shapes
//      at the same EvidenceStrength::DeepLinkWithSlug.
//
// Renamed from LinkProjectorSurfaceMarginTest 2026-09-03: `margin` is deleted,
// and with it defect (b) entirely — there is no score for a path and a query
// param to disagree about, so "parity" is not something that can be tuned back
// out. Defect (a) survives as a real question asked honestly, `contested`:
// did a rule for a DIFFERENT surface match? Two rules agreeing about one
// surface is the opposite of ambiguity, and the boolean cannot read it as
// ambiguity the way the arithmetic could.
//
// DB-free by construction: the projector runs off the compiled catalog. The
// end-to-end verdict this link must now receive is pinned in the Feature lane,
// by SignupPlacementPolicyTest.

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\LinkValidity;
use App\Routing\Projection;
use App\Routing\PublicSuffixList;
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

function openTableProject(string $url): Projection
{
    static $projector = null;
    static $canonicalizer = null;

    $projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $projector->project($canonicalizer->canonicalize($url));
}

it('does not call it contested when several detectors describe the SAME surface', function () {
    $projection = openTableProject(openTableBookingUrl());

    expect($projection->surfaceKey)->toBe('opentable.reserve')
        ->and($projection->identifier)->toBe('291533')
        // The whole of defect (a): the `rid` and `restRef` rules both match and
        // both name opentable.reserve. Agreement is not a contest.
        ->and($projection->contested)->toBeFalse();
});

it('treats a query-captured identifier exactly like a path-captured one', function () {
    $query = openTableProject(openTableBookingUrl());
    $path = openTableProject(openTableProfileUrl());

    // Opentable.php declares BOTH shapes at EvidenceStrength::DeepLinkWithSlug
    // — the catalog's own statement that they are equally strong evidence. The
    // structural score used to contradict it (40+15+4 = 59 against 40+35+4 =
    // 79) and the fix was a +20 patch chosen to make this one case come out
    // right. Nothing is summed now, so both shapes reach the same answer
    // because they ARE the same answer: this restaurant, named.
    expect($query->surfaceKey)->toBe('opentable.reserve')
        ->and($path->surfaceKey)->toBe('opentable.reserve')
        ->and($query->identifier)->toBe($path->identifier)
        ->and(LinkValidity::l1($query))->toBe(LinkValidity::l1($path));
});

it('clears every structural condition a write now depends on', function () {
    // The exact gate that dropped the live link, restated in what replaced it.
    // PlacementPolicy::decide() used to subtract an indirect penalty, then need
    // confidence >= suggest to propose at all and margin >= MIN_MARGIN to
    // apply. It now asks three yes/no questions, and this URL must answer all
    // three: the rule constrained more than the domain (so Gate 3 passes), it
    // captured an identifier (so the row is banded 'auto' and pre-ticked), and
    // no other brand claims it (so it is not demoted to a coin-toss).
    $projection = openTableProject(openTableBookingUrl());

    expect(LinkValidity::l1($projection))->toBe(LinkValidity::PASS)
        ->and($projection->identifier)->not->toBeNull()
        ->and($projection->contested)->toBeFalse();
});
