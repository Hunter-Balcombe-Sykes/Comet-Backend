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

/** SevenRooms — reservations, detect-only (logo card, no connect anywhere). */
class Sevenrooms
{
    public static function brand(): Brand
    {
        return Brand::make('sevenrooms', 'SevenRooms', 'https://www.sevenrooms.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('sevenrooms.reserve')
                ->legacyPlatform('sevenrooms')
                ->displayName('SevenRooms')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('sevenrooms.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
