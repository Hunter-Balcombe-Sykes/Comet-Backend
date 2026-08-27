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
 * StyleSeat (T27a, 2026-08-28) — US barber/stylist bookings
 * (styleseat.com/<handle>). Link-only.
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
                )
                ->build(),
        ];
    }
}
