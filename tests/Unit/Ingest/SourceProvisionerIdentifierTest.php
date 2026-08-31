<?php

use App\Ingest\SourceProvisioner;
use Tests\TestCase;

// menuStoreUrl reads the partna.menu.platforms registry, so these need a
// booted container; Pest.php binds TestCase to Feature only.
uses(TestCase::class)->in(__FILE__);

/**
 * The identifier normalisers behind SourceProvisioner::identifierFor(). They
 * decide whether a connection provisions an ingest.sources row at all, so a
 * normaliser that returns a plausible-but-dead identifier does not fail
 * loudly — it books a source that can only ever report unavailable.
 *
 * Reflection rather than widened visibility, the same convention as
 * MenuCollectionsNormalizeRowTest / UberEatsItemUrlTest: these are private
 * because nothing outside the class may call them, and a test is not a
 * reason to change that.
 */
function fbUrl(mixed $value): ?string
{
    return (new ReflectionMethod(SourceProvisioner::class, 'facebookPageUrl'))
        ->invoke(new SourceProvisioner, $value);
}

it('refuses a profile.php link instead of truncating its id away', function () {
    // Bondi Junction Dental, 2026-08-31: this shape provisioned
    // "https://www.facebook.com/profile.php" and the source went unavailable.
    expect(fbUrl('https://www.facebook.com/profile.php?id=100068321000028'))->toBeNull()
        ->and(fbUrl('https://www.facebook.com/profile.php'))->toBeNull()
        ->and(fbUrl('https://m.facebook.com/profile.php?id=123456789012'))->toBeNull()
        ->and(fbUrl('https://www.facebook.com/profile.php/'))->toBeNull()
        ->and(fbUrl('https://fb.com/profile.php?id=123456789012'))->toBeNull();
});

it('still resolves the shapes it always did', function () {
    expect(fbUrl('https://www.facebook.com/RayWhiteDoubleBay'))
        ->toBe('https://www.facebook.com/RayWhiteDoubleBay')
        ->and(fbUrl('https://www.facebook.com/pages/Domaine-Chandon-Winery/369992769701923'))
        ->toBe('https://www.facebook.com/369992769701923')
        ->and(fbUrl('https://www.facebook.com/303055460055792'))
        ->toBe('https://www.facebook.com/303055460055792')
        ->and(fbUrl('@RayWhiteDoubleBay'))
        ->toBe('https://www.facebook.com/RayWhiteDoubleBay');
});

function menuUrl(string $platform, mixed $value): ?string
{
    return (new ReflectionMethod(SourceProvisioner::class, 'menuStoreUrl'))
        ->invoke(new SourceProvisioner, $platform, $value);
}

it('refuses an ordering url that is not a store page', function () {
    // Guzman y Gomez, 2026-08-31: a /brand/ landing page provisioned a menu
    // source that never ran, on a site whose Order button was live.
    expect(menuUrl('uber-eats', 'https://www.ubereats.com/au/brand/guzman-y-gomez'))->toBeNull()
        ->and(menuUrl('uber-eats', 'https://www.ubereats.com/au'))->toBeNull()
        ->and(menuUrl('uber-eats', 'https://www.ubereats.com/au/feed?diningMode=DELIVERY'))->toBeNull()
        ->and(menuUrl('doordash', 'https://www.doordash.com/'))->toBeNull()
        ->and(menuUrl('doordash', 'https://www.doordash.com/food-delivery/sydney-au-restaurants/'))->toBeNull();
});

it('accepts a real store url and drops its tracking', function () {
    expect(menuUrl('uber-eats', 'https://www.ubereats.com/au/store/st-ali/nK322?utm_source=x'))
        ->toBe('https://www.ubereats.com/au/store/st-ali/nK322')
        ->and(menuUrl('uber-eats', 'https://www.ubereats.com/store/blue-bottle/abc123'))
        ->toBe('https://www.ubereats.com/store/blue-bottle/abc123')
        ->and(menuUrl('doordash', 'https://www.doordash.com/store/blue-bottle-coffee-new-york-2188491'))
        ->toBe('https://www.doordash.com/store/blue-bottle-coffee-new-york-2188491')
        ->and(menuUrl('doordash', 'https://www.doordash.com/en-CA/store/tim-hortons-toronto-123456/'))
        ->toBe('https://www.doordash.com/en-CA/store/tim-hortons-toronto-123456');
});

it('leaves square alone, whose storefront IS the host root', function () {
    // Square Online serves an ordering store at the BARE square.site root and
    // at /s/order (Catalog/Definitions/Square.php) — it has no /store/ path
    // segment at all, so a path rule here would retire every square menu
    // source. The config key is deliberately absent for this brand; the
    // host-only check stays its whole rule.
    expect(menuUrl('square', 'https://fat-tuna.square.site/'))
        ->toBe('https://fat-tuna.square.site')
        ->and(menuUrl('square', 'https://fat-tuna.square.site/s/order'))
        ->toBe('https://fat-tuna.square.site/s/order');
});
