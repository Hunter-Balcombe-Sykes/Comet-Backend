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
 * Halaxy (T27a, 2026-08-28) — AU health-practitioner directory + booking
 * (halaxy.com/book/…, /profile/…). Link-only. Directory profile links are
 * /profile/<slug>/<practitioner-type|location>/<numeric id> for both
 * practitioners and practice locations (confirmed live, batch T27b).
 */
class Halaxy
{
    public static function brand(): Brand
    {
        return Brand::make('halaxy', 'Halaxy', 'https://www.halaxy.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('halaxy.book')
                ->displayName('Halaxy')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('halaxy.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('halaxy.com')
                        ->path('#^/profile/[a-z0-9-]+/[a-z-]+/(?<id>\d+)#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.halaxy.com/profile/mr-harsh-parekh/physiotherapist/314445'),
                )
                ->build(),
        ];
    }
}
