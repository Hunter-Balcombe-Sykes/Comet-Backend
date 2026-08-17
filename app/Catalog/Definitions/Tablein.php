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
 * Tablein — WLH-label reservations brand, new link-only surface. Host from
 * WebsiteLinkHarvester::RESERVATION_HOSTS (verbatim); WLH collapses it to the
 * generic 'reservations' pseudo-platform today.
 */
class Tablein
{
    public static function brand(): Brand
    {
        return Brand::make('tablein', 'Tablein', 'https://www.tablein.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tablein.reserve')
                ->displayName('Tablein')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('tablein.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
