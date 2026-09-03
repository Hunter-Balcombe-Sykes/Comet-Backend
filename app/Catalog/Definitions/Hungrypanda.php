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
 * HungryPanda. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:477-480 —
 * plus a /shop?shopId= sibling (verified live 2026-09-03 across regional
 * subdomains uk./aus./usa./ca.hungrypanda.co, which all share
 * hungrypanda.co's registrable key so one detector covers every region)
 * capturing the shop id. Anchoring on ^/shop(/|$) excludes both the
 * chownow-style marketing pages AND the sibling /shopSettled endpoint
 * (an order-confirmation route, not a shareable store link) that a loose
 * ^/shop prefix would have swallowed.
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
                    Detector::url('hungrypanda.co')
                        ->path('#^/shop(?:/|$)#')
                        ->query('shopId')
                        ->captures('shopId')
                        ->from(IdentifierSource::Query)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://uk.hungrypanda.co/shop?shopId=23887'),
                )
                ->build(),
        ];
    }
}
