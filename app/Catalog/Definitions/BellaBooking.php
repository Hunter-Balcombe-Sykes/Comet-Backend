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
 * Bella Booking. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:441.
 */
class BellaBooking
{
    public static function brand(): Brand
    {
        return Brand::make('bella_booking', 'Bella Booking', 'https://www.bellabooking.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bella_booking.book')
                ->legacyPlatform('bella-booking')
                ->displayName('Bella Booking')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('bellabooking.com')->strength(EvidenceStrength::ProfileLink),
                    // Real business pages live under the fixed 'booking'
                    // subdomain, first path segment as the slug (verified
                    // live 2026-09-03: booking.bellabooking.com/mystylistemmy,
                    // .../booking). The marketing site's own paths
                    // (bellabooking.com/pricing, /about, /guides/...) sit on
                    // a different subdomain, so restricting to 'booking'
                    // keeps them out without a marketing-path reject list.
                    // /account/... is the real login flow
                    // (/account/login?account=<slug>) and would otherwise
                    // misread as a business named "account".
                    Detector::url('bellabooking.com')
                        ->subdomain('#^booking$#i')
                        ->path('#^/(?<slug>[a-z0-9-]+)(?:/|$)#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/account(?:/|$)#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://booking.bellabooking.com/mystylistemmy'),
                )
                ->build(),
        ];
    }
}
