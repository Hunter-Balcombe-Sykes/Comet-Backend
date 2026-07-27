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

/** Resy — reservations, detect-only (logo card, no connect anywhere). */
class Resy
{
    public static function brand(): Brand
    {
        return Brand::make('resy', 'Resy', 'https://resy.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('resy.reserve')
                ->displayName('Resy')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('resy.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
