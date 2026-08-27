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
 * Jane App (T27a, 2026-08-28) — allied-health/clinic booking, heavy in AU.
 * Clinics book at <clinic>.janeapp.com. Link-only: detect + card, no fetch.
 */
class JaneApp
{
    public static function brand(): Brand
    {
        return Brand::make('jane_app', 'Jane', 'https://jane.app');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('jane_app.book')
                ->displayName('Jane')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('janeapp.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
