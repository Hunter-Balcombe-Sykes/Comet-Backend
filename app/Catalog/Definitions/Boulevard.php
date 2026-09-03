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
 * Boulevard. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:467-470.
 * WebsiteLinkHarvester also matches boulevard.io and agrees with this key —
 * Phase 6 split BOOKING_PLATFORM per brand, so it returns 'boulevard'.
 */
class Boulevard
{
    public static function brand(): Brand
    {
        return Brand::make('boulevard', 'Boulevard', 'https://www.boulevard.io');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('boulevard.book')
                ->legacyPlatform('boulevard')
                ->displayName('Boulevard')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('boulevard.io')->strength(EvidenceStrength::ProfileLink),
                    // The self-booking overlay's real shareable link is
                    // dashboard.boulevard.io/booking/businesses/<uuid>/widget
                    // — a UUID business id, not a slug. Verified against real
                    // client-shared links (Facebook/Instagram posts for
                    // esteticamedspatexas and _hairbylissie, both carrying
                    // this exact shape). dashboard.boulevard.io reduces to
                    // this same registrable key, so no subdomain constraint
                    // is needed.
                    Detector::url('boulevard.io')
                        ->path('#^/booking/businesses/(?<id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://dashboard.boulevard.io/booking/businesses/3b37e3b1-b803-4272-885a-38dd869829c7/widget'),
                    // joinblvd.com: the live customer booking-widget host —
                    // dashboard.boulevard.io 301s onto it (plan-03 batch 5,
                    // verified against a real medspa's own shared link). No
                    // more specific detector added here: no real joinblvd.com
                    // URL with an identifiable slug/id was found this pass —
                    // only the marketing site and the dashboard.boulevard.io
                    // links above.
                    Detector::url('joinblvd.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
