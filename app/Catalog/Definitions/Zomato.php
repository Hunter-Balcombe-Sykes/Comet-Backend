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
 * Zomato — WLH-label ordering brand, new link-only surface. Host from
 * WebsiteLinkHarvester::ORDERING_HOSTS, verbatim.
 */
class Zomato
{
    public static function brand(): Brand
    {
        return Brand::make('zomato', 'Zomato', 'https://www.zomato.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('zomato.order')
                ->displayName('Zomato')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('zomato.com')->strength(EvidenceStrength::ProfileLink),
                    // /<city>/<slug>/(info|order|menu|delivery) — the required
                    // literal 3rd segment already rules out every 2-segment
                    // city/<category-listing> marketing page confirmed live
                    // (collections, best-bars-and-pubs, fine-dining-restaurants,
                    // etc.) and every city/restaurants/<cuisine> 3-segment page
                    // (bakery, pizza, ...), since none of those cuisines equal
                    // info/order/menu/delivery. No reject() needed.
                    Detector::url('zomato.com')
                        ->path('#^/[a-z-]+/(?<slug>[\w-]+)/(?:info|order|menu|delivery)/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.zomato.com/bangalore/kling-brewery-church-street-bangalore/info and https://www.zomato.com/bangalore/bangalore-food-zone-btm-bangalore/order'),
                )
                ->build(),
        ];
    }
}
