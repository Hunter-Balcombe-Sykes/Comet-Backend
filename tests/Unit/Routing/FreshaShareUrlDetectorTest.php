<?php

use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\Projection;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Fresha's own "share" button hands out `/book-now/<slug>/all-offer?share=true&pId=…`,
// not the canonical `/a/<slug>`. FreshaScraper::canonicalUrl, SourceProvisioner::
// freshaSlug and GoogleBusinessAutoSync all understand that shape; the catalog
// detector did not, so every shared booking link projected `no-rule-matched` and
// landed as a plain link card instead of a connected booking surface.

function freshaProjection(string $url): Projection
{
    static $projector = null;
    static $canonicalizer = null;

    $projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $projector->project($canonicalizer->canonicalize($url));
}

it('places a Fresha share URL on the booking surface with the salon slug', function () {
    $projection = freshaProjection('https://www.fresha.com/book-now/lush-hair-abc123/all-offer?share=true&pId=123456');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('fresha.book');
    expect($projection->identifier)->toBe('lush-hair-abc123');
});

it('places a locale-prefixed Fresha share URL on the booking surface', function () {
    $projection = freshaProjection('https://www.fresha.com/en-au/book-now/lush-hair-abc123/all-offer');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('fresha.book');
    expect($projection->identifier)->toBe('lush-hair-abc123');
});

it('places a bare Fresha share URL carrying no trailing segment', function () {
    $projection = freshaProjection('https://www.fresha.com/book-now/lush-hair-abc123');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->identifier)->toBe('lush-hair-abc123');
});

it('still places the canonical Fresha URL on the booking surface', function () {
    $projection = freshaProjection('https://www.fresha.com/a/lush-hair-abc123');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('fresha.book');
    expect($projection->identifier)->toBe('lush-hair-abc123');
});

it('does not place a share URL that names no salon', function () {
    expect(freshaProjection('https://www.fresha.com/book-now/')->matched())->toBeFalse();
});

it('does not place a look-alike host serving the same share path', function () {
    expect(freshaProjection('https://fresha-book.com/book-now/lush-hair-abc123/all-offer')->surfaceKey)->not->toBe('fresha.book');
});
