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
 * OrderMate — WLH-label ordering brand, new link-only surface. Host
 * ("ordermate.online") from WebsiteLinkHarvester::ORDERING_HOSTS, verbatim.
 * The bare domain 302s straight to order.platform.hungryhungry.com with the
 * path preserved verbatim — ordermate.online carries no marketing content of
 * its own, so a per-restaurant path shape is safe with no reject() needed.
 */
class Ordermate
{
    public static function brand(): Brand
    {
        return Brand::make('ordermate', 'OrderMate', 'https://www.ordermate.online');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ordermate.order')
                ->displayName('OrderMate')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('ordermate.online')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('ordermate.online')
                        ->path('#^/(?<slug>[\w-]+)/menu/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://ordermate.online/carolinethai2restaurant/menu (real restaurant Facebook post; the redirect target 404s today but the path-preserving 302 shape was independently confirmed live)'),
                )
                ->build(),
        ];
    }
}
