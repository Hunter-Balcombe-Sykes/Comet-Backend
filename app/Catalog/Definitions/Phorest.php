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

/** Phorest — 27-set booking, detect-only (logo card, no connect anywhere). */
class Phorest
{
    public static function brand(): Brand
    {
        return Brand::make('phorest', 'Phorest', 'https://www.phorest.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('phorest.book')
                ->displayName('Phorest')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('phorest.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
