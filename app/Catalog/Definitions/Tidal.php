<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Tidal — keyless embed, same dormant shape as Mixcloud: "Detect: none,
 * Connect: none registered". Explicitly not refreshable.
 */
class Tidal
{
    public static function brand(): Brand
    {
        return Brand::make('tidal', 'Tidal', 'https://tidal.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tidal.player')
                ->displayName('Tidal')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->note('dormant until embed set expands, §10')
                ->embed('https://embed.tidal.com/{entity_path}', 'ratio:wide', [], false)
                ->notConnectable()
                ->build(),
        ];
    }
}
