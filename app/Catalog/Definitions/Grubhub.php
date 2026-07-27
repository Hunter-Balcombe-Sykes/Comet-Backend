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
 * Grubhub. New link-only surface — today a
 * WebsiteLinkHarvester::ORDERING_HOSTS label
 * (WebsiteLinkHarvester.php:98) that collapses into the generic
 * 'online-ordering' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. Host-only detector, no capture.
 */
class Grubhub
{
    public static function brand(): Brand
    {
        return Brand::make('grubhub', 'Grubhub', 'https://www.grubhub.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('grubhub.order')
                ->displayName('Grubhub')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('grubhub.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
