<?php

use App\Ingest\SourceProvisioner;

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
