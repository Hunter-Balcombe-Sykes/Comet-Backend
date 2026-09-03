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
    // ko-fi.com/acme and github.com/acme LEFT this list 2026-08-18: they now
    // carry a `(?<handle>)` capture detector (F12), so a profile-shaped URL is
    // ProfileLink evidence like x.com/{handle} — see the test below. Only the
    // genuinely host-only shapes must stay below suggest.
    // booksy.com/en-us/{id}_{slug} LEFT this list 2026-09-03 for the same
    // reason ko-fi and github left it in F12: the pattern fleet gave Booksy a
    // real `(?<business>\d+)_` detector, so that URL is now a deep-link match
    // at 79, not a host-only recovery. It stays asserted as booksy.book by the
    // test above — what changed is that we can now say WHICH salon.
    $recovered = [
        'https://ko-fi.com/gold',              // reserved path → host-only fallback
        'https://ra.co/events/1234567',
        'https://github.com/features/actions', // multi-segment → host-only fallback
    ];

    $lowestSuggest = 45; // RoutingPolicy: social/content, the most permissive class

    foreach ($recovered as $url) {
        expect(projectUrl($url)->confidence)->toBeLessThan($lowestSuggest, $url);
    }

    expect(Verdict::Note->writesIntent())->toBeFalse();
});

it('scores a profile-shaped URL on a handle-capturing brand like any other ProfileLink (F12)', function () {
    foreach (['https://ko-fi.com/acme' => 'ko_fi.page', 'https://github.com/acme' => 'github.profile', 'https://acme.substack.com' => 'substack.publication'] as $url => $surface) {
        $p = projectUrl($url);
        expect($p->matched())->toBeTrue($url)
            ->and($p->surfaceKey)->toBe($surface)
            ->and($p->confidence)->toBeGreaterThanOrEqual(45, $url);
    }
});
