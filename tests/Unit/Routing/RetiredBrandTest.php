<?php

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\PlacementPolicy;
use App\Routing\PublicSuffixList;
use App\Routing\RoutingContext;
use App\Routing\Rulepack;
use App\Routing\Verdict;
use App\Services\Platforms\Strategies\Connect\BrandLinkConnect;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Menulog ceased Australian operations on 2025-11-26 and menulog.com.au was
// its only market, so `menulog.order` is lifecycle=retired as of 2026-09-03.
//
// The reason this file exists is that retirement had TWO lanes and only one of
// them was wired. PlacementPolicy.php:83 has always routed a retired surface to
// Verdict::Note — a harvest keeps the link and never offers a connection. But
// nothing on the MANUAL lane read lifecycle at all: BrandLinkConnect asked
// WebsiteLinkHarvester::classify(), which is host-only and knows nothing about
// the catalog, so a paste still connected a dead brand and attached a live
// ordering CTA to it. The guard now sits on the strategy, which is the single
// choke point for every derived brand surface, so retiring any brand in future
// closes both lanes at once rather than only the harvest.
//
// Both assertions are deliberately about ONE brand's real host. A test that
// mints a synthetic retired surface would pass against a broken catalog.

function retiredProject(string $url)
{
    static $p = null;
    static $c = null;
    $p ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $c ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $p->project($c->canonicalize($url));
}

it('never offers a retired brand as a connection, and says why', function (string $url) {
    $placement = (new PlacementPolicy)->decide(
        retiredProject($url),
        new RoutingContext(user: null, preAccount: true),
    );

    expect($placement->verdict)->toBe(Verdict::Note)
        ->and($placement->blockReason)->toBe('retired');
})->with([
    'menulog — ceased AU operations 2025-11-26' => 'https://www.menulog.com.au/restaurants-example-venue/menu',
    'genbook — sunset by Booksy, 301s to booksy.com' => 'https://www.genbook.com/bookings/slot/reservation/example',
]);

it('keeps the retired brand as a plain link rather than dropping it', function () {
    // Note is the "kept, never dropped" verdict — the distinction that matters
    // to someone who already has this link on their page. Only Reject blocks.
    $placement = (new PlacementPolicy)->decide(
        retiredProject('https://www.menulog.com.au/restaurants-example-venue/menu'),
        new RoutingContext(user: null, preAccount: true),
    );

    expect($placement->verdict)->not->toBe(Verdict::Reject);
});

it('refuses a manual paste of a retired brand', function (string $slug, string $label, string $key, string $url) {
    $result = (new BrandLinkConnect($slug, $label, $key))->resolve($url);

    expect($result->failed())->toBeTrue()
        ->and($result->error)->toContain('no longer operating');
})->with([
    'menulog' => ['menulog', 'Menulog', 'menulog.order', 'https://www.menulog.com.au/restaurants-example-venue/menu'],
    'genbook' => ['genbook', 'Genbook', 'genbook.book', 'https://www.genbook.com/bookings/slot/reservation/example'],
]);

it('still connects a brand that is trading', function () {
    // The guard must be lifecycle-specific, not a blanket refusal — this is the
    // assertion that fails if someone widens it later.
    $result = (new BrandLinkConnect('uber-eats', 'Uber Eats', 'uber_eats.order'))
        ->resolve('https://www.ubereats.com/au/store/example-venue/abc123');

    expect($result->failed())->toBeFalse();
});
