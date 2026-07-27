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
 * Buy Me a Coffee. Registered (PRSP:136, linkOnly) but with zero connect
 * wiring and absent from WebsiteLinkHarvester entirely (inventory D2 #5) —
 * effectively unreachable today except a direct DB write. Host is this
 * file's own addition (well-known real domain, not sourced from any
 * existing regex in this codebase).
 */
class Buymeacoffee
{
    public static function brand(): Brand
    {
        return Brand::make('buymeacoffee', 'Buy Me a Coffee', 'https://www.buymeacoffee.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('buymeacoffee.page')
                ->displayName('Buy Me a Coffee')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('buymeacoffee.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
