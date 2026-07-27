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
 * Dribbble. Registered (PRSP:135, linkOnly) but with zero connect wiring —
 * host verbatim from WebsiteLinkHarvester::SOCIAL_HOSTS
 * (WebsiteLinkHarvester.php:45), the harvester's only awareness of this
 * brand. No capture: no normalizer grammar exists to translate faithfully.
 */
class Dribbble
{
    public static function brand(): Brand
    {
        return Brand::make('dribbble', 'Dribbble', 'https://dribbble.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('dribbble.profile')
                ->displayName('Dribbble')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('dribbble.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
