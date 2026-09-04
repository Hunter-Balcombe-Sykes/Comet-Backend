<?php

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\LinkValidity;
use App\Routing\Projection;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// square.order became detectable 2026-08-26 (menu deep-links plan A1): a
// square.site URL carrying the /s/order ordering path is unambiguously
// ordering. The BARE square.site host stays square.book's by design (booking
// wins the ambiguous host — a live-verified reality: ordering stores also
// serve at the root, and those are disambiguated by the connect flow's
// storefront-marker probe, not by URL). These tests pin BOTH outcomes so
// neither claim drifts.
//
// Rewritten 2026-09-03 with the confidence system. These used to assert
// "clears the suggest bar" / "above the auto bar" against RoutingPolicy's
// threshold table; both the bars and the table are gone. The claim underneath
// them was structural all along — the rule that matched constrained a path or
// a subdomain, so it describes an account page and not merely the brand — and
// LinkValidity::l1() states that directly.
//
// Known gap, deliberately NOT asserted here as though it were correct: none of
// Square's matched detectors sets `identifier_capture`, so every projection
// below carries identifier === null. That makes the link a `suggest` band that
// can never auto-place, and SourceReconciler falls back to storing the whole
// URL where the account id belongs — the nameless connection card.
//
// square.book's patterns ALREADY name `(?<merchant>…)`; the projector captures
// it and throws it away because nothing points at it. One of 7 such detectors
// across 5 surfaces — tracked in
// partna-monorepo/docs/2026-09-03-platform-link-system-run.md, not papered
// over here.

function squareProjection(string $url): Projection
{
    static $projector = null;
    static $canonicalizer = null;

    $projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $projector->project($canonicalizer->canonicalize($url));
}

it('places a square.site /s/order URL on the ordering surface as an account-shaped match', function () {
    $projection = squareProjection('https://ischia-restaurant.square.site/s/order');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('square.order');
    expect(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);

    // square.book's bare-host rule matches this URL too, and losing to the
    // /s/order path rule is the catalog working as designed — not two brands
    // claiming one link. A host-only runner-up must never make a specific
    // winner contested, or every Square ordering link becomes a question whose
    // answer the catalog already gave.
    expect($projection->contested)->toBeFalse();
});

it('keeps a bare square.site root on the booking surface (host default unchanged)', function () {
    $projection = squareProjection('https://acmestore.square.site/');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('square.book');
});

it('keeps squareup.com on the booking surface', function () {
    $projection = squareProjection('https://squareup.com/appointments/book/abc');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('square.book');
});

it('does not claim an /s/order path on a non-square host', function () {
    $projection = squareProjection('https://example.com/s/order');

    expect($projection->surfaceKey)->not->toBe('square.order');
});

it('places a Square Appointments deep link on square.book as an account-shaped match', function () {
    $projection = squareProjection('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&color=000000&team_member_id=TM-qREuvGrHGnJ5Z');
    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('square.book');
    expect(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);
    expect($projection->contested)->toBeFalse();
});

it('places the app.squareup.com booking_flow_url shape on square.book', function () {
    $projection = squareProjection('https://app.squareup.com/appointments/book/7rn54rnv21ng7n/LAJZK7J54JGCW/start');
    expect($projection->surfaceKey)->toBe('square.book');
    expect(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);
});

it('places a merchant root with no location on square.book', function () {
    $projection = squareProjection('https://book.squareup.com/appointments/7rn54rnv21ng7n');
    expect($projection->surfaceKey)->toBe('square.book');
    expect(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);
});
