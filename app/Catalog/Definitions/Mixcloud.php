<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Mixcloud — keyless embed with zero wiring to reach it: "Detect: none,
 * Connect: none registered" (inventory). notConnectable() + no detector is
 * the honest encoding — there is currently no path, manual or automatic, that
 * populates a mixcloud connection at all. Explicitly not refreshable.
 */
class Mixcloud
{
    public static function brand(): Brand
    {
        return Brand::make('mixcloud', 'Mixcloud', 'https://www.mixcloud.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('mixcloud.player')
                ->displayName('Mixcloud')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->note('dormant until embed set expands, §10')
                ->embed('https://www.mixcloud.com/widget/iframe/?feed={url}', 'fixed:120', [], false)
                ->notConnectable()
                ->build(),
        ];
    }
}
