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
 * Timely — 27-set booking. Real host is gettimely.com, not timely.com
 * (PRSP:433's HostMatch, verbatim). A business's live online-booking widget
 * is NOT the docs' "mini website" tenant subdomain (never found a live
 * example of that shape) — it's a fixed host, bookings.gettimely.com, with
 * the tenant as a path segment: /<tenant>[/<location>]/book (confirmed
 * live: bookings.gettimely.com/rmhairroom/book,
 * .../quintessentialbeautyshop/bb/book). The path detector below has no
 * subdomain constraint, so it matches that host (LinkProjector treats a
 * null subdomain_pattern as "any subdomain").
 */
class Timely
{
    public static function brand(): Brand
    {
        return Brand::make('timely', 'Timely', 'https://www.gettimely.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('timely.book')
                ->legacyPlatform('timely')
                ->displayName('Timely')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('gettimely.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('gettimely.com')
                        ->path('#^/(?<tenant>[a-z0-9][a-z0-9-]{1,62})(?:/[a-z0-9-]{1,62})?/book/?$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://bookings.gettimely.com/rmhairroom/book'),
                )
                ->build(),
        ];
    }
}
