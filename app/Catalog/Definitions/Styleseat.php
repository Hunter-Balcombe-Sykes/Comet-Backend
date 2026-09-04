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
 * StyleSeat (T27a, 2026-08-28) — US barber/stylist bookings. A pro's own
 * page is styleseat.com/m/<handle> — the app 301s that to the canonical
 * /m/v/<handle> (confirmed live: /m/v/bookstyles, a real stylist's page in
 * Warren, MI). /m/ is also the app's own account-route namespace
 * (my-profile, appointments, login, ... all real 200 routes there), so
 * those are rejected the same way the app itself reserves them.
 */
class Styleseat
{
    public static function brand(): Brand
    {
        return Brand::make('styleseat', 'StyleSeat', 'https://www.styleseat.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('styleseat.book')
                ->displayName('StyleSeat')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('styleseat.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('styleseat.com')
                        ->path('#^/m/(?:v/)?(?<handle>[a-zA-Z0-9_.-]{2,60})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/m/(?:v/)?(?:my-profile|appointments|login|signup|search|settings|dashboard|business|book|messages|notifications|pro|help)(?:/|$)#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.styleseat.com/m/v/bookstyles'),
                )
                ->build(),
        ];
    }
}
