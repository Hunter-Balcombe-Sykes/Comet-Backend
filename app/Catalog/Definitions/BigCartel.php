<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\IdentifierSource;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Big Cartel storefronts — the second half of WebsiteLinkHarvester's
 * decisive SHOP_HOSTS pair, restored alongside Shopify.
 */
class BigCartel
{
    public static function brand(): Brand
    {
        return Brand::make('bigcartel', 'Big Cartel', 'https://www.bigcartel.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bigcartel.store')
                ->displayName('Big Cartel store')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Domain)
                ->refreshEvery(0)
                ->canonicalUrl('https://{store}.bigcartel.com')
                ->notConnectable()
                ->detect(
                    Detector::url('bigcartel.com')
                        ->subdomain('#^(?!www$)(?<store>[a-z0-9][a-z0-9-]{1,60})$#')
                        ->captures('store')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->note('connects through the commerce probe (§11), not a catalog connect capability')
                ->build(),
        ];
    }
}
