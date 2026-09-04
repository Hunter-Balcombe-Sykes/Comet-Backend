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
 * DoorDash. New link-only surface — today a
 * WebsiteLinkHarvester::ORDERING_HOSTS label
 * (WebsiteLinkHarvester.php:90) that collapses into the generic
 * 'online-ordering' pseudo-bucket (also present verbatim in
 * config('partna.menu.platforms').doordash's host_pattern,
 * config/partna.php:857); this surface is the P1 upgrade to a first-class
 * brand. Host-only detector, no capture — plus a /store/<slug> sibling
 * (verified live 2026-09-03) that captures the real store identifier.
 * Anchoring on ^/store/ already excludes every marketing/browse path found
 * (/food-delivery/<city>, /cuisine/, /food-near-me, /gift-cards/), so no
 * ->reject() is needed.
 */
class Doordash
{
    public static function brand(): Brand
    {
        return Brand::make('doordash', 'DoorDash', 'https://www.doordash.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('doordash.order')
                ->displayName('DoorDash')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('doordash.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('doordash.com')
                        ->path('#^/store/(?<store>[\w-]+)#')
                        ->captures('store')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.doordash.com/store/mission-ranch-restaurant-mission-viejo-954502'),
                )
                ->build(),
        ];
    }
}
