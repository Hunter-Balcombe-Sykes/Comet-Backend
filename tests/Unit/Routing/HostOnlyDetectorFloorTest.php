<?php

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use App\Routing\Verdict;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// N1/N4 (2026-08-11 Instagram build wave): a host-only detector scored 40,
// lost 8 for carrying a path, and died against a floor of 35 — so the ONLY
// URL shape it could match was the bare host, which carries no identity at
// all. 64 of 114 surfaces were unmatchable for any real URL.
//
// The floor was doing no safety work: RoutingPolicy's thresholds (auto 70-80,
// suggest 45-55) sit far above anything this affects, so a recovered match
// lands as Verdict::Note, which writesIntent() === false. It only destroyed
// the IDENTITY — and identity is what LinkRouter needs to stop spending a
// commerce probe discovering that ko-fi.com is Ko-fi.

function floorProjector(): LinkProjector
{
    static $p = null;

    return $p ??= new LinkProjector(Rulepack::fromCompiledCatalog());
}

function floorCanonicalizer(): IriCanonicalizer
{
    static $c = null;

    return $c ??= new IriCanonicalizer(PublicSuffixList::instance());
}

function projectUrl(string $url)
{
    return floorProjector()->project(floorCanonicalizer()->canonicalize($url));
}

it('identifies a host-only ProfileLink brand from a real profile URL', function () {
    $projection = projectUrl('https://ko-fi.com/acme');

    expect($projection->matched())->toBeTrue()
        ->and($projection->surfaceKey)->toBe('ko_fi.page');
});

it('identifies a host-only MarketplaceListing brand from a deep URL', function () {
    $projection = projectUrl('https://ra.co/events/1234567');

    expect($projection->matched())->toBeTrue()
        ->and($projection->surfaceKey)->toBe('resident_advisor.tickets');
});

it('identifies the host-only booking and ordering brands the legacy table already knew', function () {
    $expected = [
        'https://booksy.com/en-us/12345_the-salon' => 'booksy.book',
        'https://www.doordash.com/store/some-venue-123' => 'doordash.order',
        'https://resy.com/cities/ny/venues/some-venue' => 'resy.reserve',
        'https://github.com/acme' => 'github.profile',
    ];

    $actual = [];
    foreach ($expected as $url => $_) {
        $p = projectUrl($url);
        $actual[$url] = $p->matched() ? $p->surfaceKey : 'NO MATCH';
    }

    expect($actual)->toBe($expected);
});

// The safety argument for lowering the floor, pinned rather than asserted in
// prose: everything recovered above sits below every suggest threshold, and a
// verdict below suggest is a Note, which writes nothing.
it('keeps every recovered host-only match below the lowest suggest threshold', function () {
    $recovered = [
        'https://ko-fi.com/acme',
        'https://ra.co/events/1234567',
        'https://booksy.com/en-us/12345_the-salon',
        'https://github.com/acme',
    ];

    $lowestSuggest = 45; // RoutingPolicy: social/content, the most permissive class

    foreach ($recovered as $url) {
        expect(projectUrl($url)->confidence)->toBeLessThan($lowestSuggest, $url);
    }

    expect(Verdict::Note->writesIntent())->toBeFalse();
});
