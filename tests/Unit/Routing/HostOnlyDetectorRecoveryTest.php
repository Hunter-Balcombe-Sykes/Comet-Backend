<?php

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\LinkValidity;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// N1/N4 (2026-08-11 Instagram build wave): a host-only detector scored 40,
// lost 8 for carrying a path, and died against a floor of 35 — so the ONLY
// URL shape it could match was the bare host, which carries no identity at
// all. 64 of 114 surfaces were unmatchable for any real URL.
//
// Renamed from HostOnlyDetectorFloorTest 2026-09-03: there is no floor any
// more, and no score for one to sit under. What survives is the finding, which
// was never about the number — a host-only rule must still MATCH its brand, or
// LinkRouter spends a commerce probe discovering that ko-fi.com is Ko-fi.
//
// The safety argument the floor's own test used to make in arithmetic ("every
// recovered match lands below the lowest suggest threshold, and below-suggest
// is a Note, which writes nothing") is now made structurally, and by the code
// rather than by a comparison: a host-only match constrains nothing beyond the
// registrable domain, so LinkValidity::l1() reads WEAK, and PlacementPolicy's
// Gate 3 turns a WEAK match on a surface we hold a shape for into a Note. Same
// guarantee, no tuning surface.

function hostOnlyProjector(): LinkProjector
{
    static $p = null;

    return $p ??= new LinkProjector(Rulepack::fromCompiledCatalog());
}

function hostOnlyCanonicalizer(): IriCanonicalizer
{
    static $c = null;

    return $c ??= new IriCanonicalizer(PublicSuffixList::instance());
}

function hostOnlyProject(string $url)
{
    return hostOnlyProjector()->project(hostOnlyCanonicalizer()->canonicalize($url));
}

it('identifies a host-only ProfileLink brand from a real profile URL', function () {
    $projection = hostOnlyProject('https://ko-fi.com/acme');

    expect($projection->matched())->toBeTrue()
        ->and($projection->surfaceKey)->toBe('ko_fi.page');
});

it('identifies a host-only MarketplaceListing brand from a deep URL', function () {
    $projection = hostOnlyProject('https://ra.co/events/1234567');

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
        $p = hostOnlyProject($url);
        $actual[$url] = $p->matched() ? $p->surfaceKey : 'NO MATCH';
    }

    expect($actual)->toBe($expected);
});

it('leaves every recovered host-only match naming the BRAND and not an account', function () {
    // ko-fi.com/acme and github.com/acme LEFT this list 2026-08-18: they now
    // carry a `(?<handle>)` capture detector (F12), so a profile-shaped URL is
    // ProfileLink evidence like x.com/{handle} — see the test below. Only the
    // genuinely host-only shapes belong here.
    // booksy.com/en-us/{id}_{slug} LEFT this list 2026-09-03: the pattern fleet
    // gave Booksy a real `(?<business>\d+)_` detector, so that URL is now a
    // deep-link match. It stays asserted as booksy.book by the test above —
    // what changed is that we can now say WHICH salon.
    $recovered = [
        'https://ko-fi.com/gold',              // reserved path → host-only fallback
        'https://ra.co/events/1234567',
        'https://github.com/features/actions', // multi-segment → host-only fallback
    ];

    foreach ($recovered as $url) {
        $projection = hostOnlyProject($url);

        expect($projection->matched())->toBeTrue($url)
            ->and(LinkValidity::l1($projection))->toBe(LinkValidity::WEAK, $url)
            // The projector must not invent one either. A host-only match's
            // "identifier" would be the whole URL, and passing that on is how a
            // connection card ends up named after a link.
            ->and($projection->identifier)->toBeNull($url);
    }
});

it('reads a profile-shaped URL on a handle-capturing brand as a real account (F12)', function () {
    foreach (['https://ko-fi.com/acme' => 'ko_fi.page', 'https://github.com/acme' => 'github.profile', 'https://acme.substack.com' => 'substack.publication'] as $url => $surface) {
        $p = hostOnlyProject($url);

        expect($p->matched())->toBeTrue($url)
            ->and($p->surfaceKey)->toBe($surface)
            ->and(LinkValidity::l1($p))->toBe(LinkValidity::PASS, $url)
            ->and($p->identifier)->toBe('acme', $url);
    }
});
