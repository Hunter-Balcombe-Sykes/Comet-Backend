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
 * HungryPanda. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:477-480.
 */
class Hungrypanda
{
    public static function brand(): Brand
    {
        return Brand::make('hungrypanda', 'HungryPanda', 'https://www.hungrypanda.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('hungrypanda.order')
                ->legacyPlatform('hungrypanda')
                ->displayName('HungryPanda')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('hungrypanda.co')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
