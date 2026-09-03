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
 * SkipTheDishes — WLH-label ordering brand, new link-only surface. Host from
 * WebsiteLinkHarvester::ORDERING_HOSTS, verbatim.
 */
class Skipthedishes
{
    public static function brand(): Brand
    {
        return Brand::make('skipthedishes', 'SkipTheDishes', 'https://www.skipthedishes.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('skipthedishes.order')
                ->displayName('SkipTheDishes')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('skipthedishes.com')->strength(EvidenceStrength::ProfileLink),
                    // Restaurant menu pages are two-segment: <slug>/menu/. Direct
                    // fetch bot-403s (site-wide, even /sitemap.xml — not page-
                    // specific), but the exact URL below is search-engine indexed
                    // under Skip's standard restaurant-page title template.
                    Detector::url('skipthedishes.com')
                        ->path('#^/(?<slug>[\w-]+)/menu/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.skipthedishes.com/restaurants-songs-kitchen/menu/'),
                )
                ->build(),
        ];
    }
}
