<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Halaxy (T27a, 2026-08-28) — AU health-practitioner directory + booking
 * (halaxy.com/book/…, /profile/…). Link-only.
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
                )
                ->build(),
        ];
    }
}
