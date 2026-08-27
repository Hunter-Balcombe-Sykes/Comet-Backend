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
 * Cliniko (T27a, 2026-08-28) — AU practice management; patient bookings at
 * <clinic>.<shard>.cliniko.com/bookings. Link-only.
 */
class Cliniko
{
    public static function brand(): Brand
    {
        return Brand::make('cliniko', 'Cliniko', 'https://www.cliniko.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('cliniko.book')
                ->displayName('Cliniko')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('cliniko.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
