<?php

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// 2026-08-18 (gsnwilliams): an Eventbrite EVENT link found in a Linktree
// became an "Eventbrite" platform. Two causes, one fix each:
//   - eventbrite.com/e/… carried a MarketplaceListing "route to event flow"
//     detector — but a path detector scores 71 (40 + 35 − 4), over the events
//     suggest band, and an indirect origin auto-applies that band, so it PLACED
//     an organiser connection whose resource_id was the whole event URL;
//   - eventbrite.com.au/e/… had no rule at all and fell to the seeder, which
//     minted a resource_kind='event' connection row nobody read.
// /e/ is now a reservedPath on eventbrite.organiser: every regional host
// projects no-rule-matched → Note → the importer seeds a pool ITEM only.

function ebProject(string $url)
{
    static $p = null;
    static $c = null;
    $p ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $c ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $p->project($c->canonicalize($url));
}

it('never projects a single-event /e/ URL onto the organiser surface, on any regional host', function (string $url) {
    $projection = ebProject($url);

    expect($projection->matched())->toBeFalse()
        ->and($projection->reason)->toBe('no-rule-matched');
})->with([
    'com' => 'https://www.eventbrite.com/e/hobart-mens-hair-workshop-tickets-1993984195405?aff=oddtdtcreator',
    'com.au' => 'https://www.eventbrite.com.au/e/hobart-mens-hair-workshop-tickets-1993984195405?aff=oddtdtcreator',
    'co.uk' => 'https://www.eventbrite.co.uk/e/some-event-tickets-1',
    'bare /e' => 'https://eventbrite.com/e/',
]);

it('still places an organiser /o/ URL on every regional host', function (string $url) {
    $projection = ebProject($url);

    expect($projection->matched())->toBeTrue()
        ->and($projection->surfaceKey)->toBe('eventbrite.organiser')
        ->and($projection->identifier)->toBe('melbourne-food-collective-1234');
})->with([
    'com' => 'https://www.eventbrite.com/o/melbourne-food-collective-1234',
    'com.au' => 'https://www.eventbrite.com.au/o/melbourne-food-collective-1234',
]);
