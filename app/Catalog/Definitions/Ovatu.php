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

/** Ovatu — 27-set booking, detect-only (logo card, no connect anywhere). */
class Ovatu
{
    public static function brand(): Brand
    {
        return Brand::make('ovatu', 'Ovatu', 'https://ovatu.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ovatu.book')
                ->legacyPlatform('ovatu')
                ->displayName('Ovatu')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('ovatu.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
