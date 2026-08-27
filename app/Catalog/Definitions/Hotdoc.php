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
 * HotDoc (T27a, 2026-08-28) — AU GP/medical bookings. Link-only.
 */
class Hotdoc
{
    public static function brand(): Brand
    {
        return Brand::make('hotdoc', 'HotDoc', 'https://www.hotdoc.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('hotdoc.book')
                ->displayName('HotDoc')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('hotdoc.com.au')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
