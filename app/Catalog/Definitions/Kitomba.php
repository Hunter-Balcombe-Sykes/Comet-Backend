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
 * Kitomba. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:435.
 */
class Kitomba
{
    public static function brand(): Brand
    {
        return Brand::make('kitomba', 'Kitomba', 'https://www.kitomba.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('kitomba.book')
                ->legacyPlatform('kitomba')
                ->displayName('Kitomba')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('kitomba.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
