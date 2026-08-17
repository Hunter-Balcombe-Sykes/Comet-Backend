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

/** Zenoti — 27-set booking, detect-only (logo card, no connect anywhere). */
class Zenoti
{
    public static function brand(): Brand
    {
        return Brand::make('zenoti', 'Zenoti', 'https://www.zenoti.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('zenoti.book')
                ->displayName('Zenoti')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('zenoti.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
