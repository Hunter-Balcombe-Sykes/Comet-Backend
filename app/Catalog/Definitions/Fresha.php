<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\IdentifierSource;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Fresha. Bespoke connect (FreshaController::connect()) — no connect
 * capability. Its connectFetch strategy (FreshaConnectFetch) is distinct
 * from the scheduled-refresh strategy (FreshaFetch) — PlatformDescriptor::
 * connectFetchStrategy() defaults to fetchStrategy() for every other
 * platform; Fresha is the one override (PRSP:373). Path capture translated
 * from the connectInput regex (PRSP:522), stripped of its scheme+host
 * prefix to match this catalog's path-only detector idiom (see X.php).
 * XOR: shares BOOKING_SLOT_PLATFORMS exclusivity with square/booking
 * (BASF.php:45-48) — unrelated to this catalog definition, noted for
 * context only.
 */
class Fresha
{
    public static function brand(): Brand
    {
        return Brand::make('fresha', 'Fresha', 'https://www.fresha.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('fresha.book')
                ->legacyPlatform('fresha')
                ->displayName('Fresha')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(172800)
                ->canonicalUrl('https://www.fresha.com/a/{slug}')
                ->connectFetch('connect_fetch.fresha.scrape.v1')
                ->fetch('fetch.fresha.scrape.v1')
                ->detect(
                    // `book-now` is the share URL Fresha's own button copies
                    // (`/book-now/<slug>/all-offer?share=true&pId=…`), so it is the shape
                    // most owner links actually arrive in. Anchored on `/a/` alone this
                    // surface went dark for every one of them — `no-rule-matched` →
                    // Verdict::Note → a plain link card. Same slug either way
                    // (FreshaScraper::canonicalUrl rewrites one to the other), so it is an
                    // alternative inside this one pattern rather than a second detector:
                    // two detectors mean two ids and a host anchor free to drift between
                    // them. The trailing group is what makes `book-now` reachable at all —
                    // the share URL always carries a segment after the slug — and is left
                    // open rather than pinned to `all-offer` because Fresha varies it, as
                    // SourceProvisioner::freshaSlug already assumes.
                    Detector::url('fresha.com')
                        ->path('#^/(?:[a-z]{2,3}(-[a-z]{2})?/)?(?:a|book-now)/(?<slug>[a-z0-9-]+)(?:/.*)?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->note('bespoke connect flow (P1)')
                ->build(),
        ];
    }
}
