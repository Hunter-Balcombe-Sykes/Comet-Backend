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
 * FareHarbor (T27a, 2026-08-28) — tours/activities booking
 * (fareharbor.com/embeds/book/<operator>/). Link-only.
 */
class Fareharbor
{
    public static function brand(): Brand
    {
        return Brand::make('fareharbor', 'FareHarbor', 'https://fareharbor.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('fareharbor.book')
                ->displayName('FareHarbor')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('fareharbor.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
