<?php

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\Projection;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use App\Routing\RoutingPolicy;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// square.order became detectable 2026-08-26 (menu deep-links plan A1): a
// square.site URL carrying the /s/order ordering path is unambiguously
// ordering. The BARE square.site host stays square.book's by design (booking
// wins the ambiguous host — a live-verified reality: ordering stores also
// serve at the root, and those are disambiguated by the connect flow's
// storefront-marker probe, not by URL). These tests pin BOTH outcomes so
// neither claim drifts.

function squareProjection(string $url): Projection
{
    static $projector = null;
    static $canonicalizer = null;

    $projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $projector->project($canonicalizer->canonicalize($url));
}

it('places a square.site /s/order URL on the ordering surface, clearing the suggest bar', function () {
    $projection = squareProjection('https://ischia-restaurant.square.site/s/order');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('square.order');
    expect($projection->confidence)->toBeGreaterThanOrEqual(RoutingPolicy::suggestThreshold('ordering'));
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
