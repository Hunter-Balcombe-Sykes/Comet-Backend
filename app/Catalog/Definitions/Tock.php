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
 * Tock — reservations, detect-only. Two real hosts (PRSP:472-475's
 * HostMatch, verbatim): exploretock.com and tock.com. WLH's own Tock pattern
 * is narrower (exploretock.com only) — the registry's is used here since it's
 * this brand's actual detect() registration.
 */
class Tock
{
    public static function brand(): Brand
    {
        return Brand::make('tock', 'Tock', 'https://www.exploretock.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tock.reserve')
                ->displayName('Tock')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('exploretock.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('tock.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
