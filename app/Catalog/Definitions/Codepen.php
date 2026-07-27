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
 * CodePen. Registered (PRSP:135, linkOnly) but with zero connect wiring and
 * absent from WebsiteLinkHarvester entirely (inventory D2 #5). Host is this
 * file's own addition (well-known real domain).
 */
class Codepen
{
    public static function brand(): Brand
    {
        return Brand::make('codepen', 'CodePen', 'https://codepen.io');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('codepen.profile')
                ->displayName('CodePen')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('codepen.io')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
