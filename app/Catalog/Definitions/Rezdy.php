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
 * Rezdy (T27a, 2026-08-28) — AU tours/activities booking
 * (<operator>.rezdy.com widgets). Link-only.
 */
class Rezdy
{
    public static function brand(): Brand
    {
        return Brand::make('rezdy', 'Rezdy', 'https://www.rezdy.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('rezdy.book')
                ->displayName('Rezdy')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('rezdy.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
