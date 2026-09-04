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
 * Chope. New link-only surface — today a
 * WebsiteLinkHarvester::RESERVATION_HOSTS label
 * (WebsiteLinkHarvester.php:65) that collapses into the generic
 * 'reservations' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. Host-only detector kept (no-match is a meaningful
 * downstream signal) alongside a restaurant-page sibling: real venue pages
 * live at /<city>-restaurants/restaurant/<slug> (confirmed live, e.g.
 * https://www.chope.co/singapore-restaurants/restaurant/po-restaurant), which
 * the /pages/ marketing tree (for-restaurants, dinerfaq, ...) never matches.
 */
class Chope
{
    public static function brand(): Brand
    {
        return Brand::make('chope', 'Chope', 'https://www.chope.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('chope.reserve')
                ->displayName('Chope')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('chope.co')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('chope.co')
                        ->path('#^/[a-z0-9-]+-restaurants/restaurant/(?<slug>[^/?]+)#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.chope.co/singapore-restaurants/restaurant/po-restaurant'),
                )
                ->build(),
        ];
    }
}
