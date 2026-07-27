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
 * Gumroad. Registered (PRSP:136, linkOnly) with zero connect wiring and
 * absent from WebsiteLinkHarvester entirely (inventory D2 #5). Routes Shop
 * per LegacyPlatformMap ('gumroad.store' => 'shop'), shelved under
 * Commerce. Host is this file's own addition (well-known real domain); no
 * capture — no normalizer grammar exists to translate faithfully, despite
 * Gumroad storefronts conventionally living at a subdomain.
 */
class Gumroad
{
    public static function brand(): Brand
    {
        return Brand::make('gumroad', 'Gumroad', 'https://gumroad.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('gumroad.store')
                ->displayName('Gumroad')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('gumroad.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
