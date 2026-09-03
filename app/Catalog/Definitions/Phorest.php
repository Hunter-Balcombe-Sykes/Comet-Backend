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
 * Phorest — 27-set booking. A salon's own page lives at
 * phorest.com/salon/<slug> (confirmed live: houseofbeautybarnsley,
 * salonh, vistabellesalon, nicolebryansalon — real distinct salons
 * indexed under this path; /book/salons/<slug> 301s to the same
 * canonical form). No reject list: every real Phorest marketing path
 * found (features/, industry/, blog/, us/) lives outside /salon/.
 */
class Phorest
{
    public static function brand(): Brand
    {
        return Brand::make('phorest', 'Phorest', 'https://www.phorest.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('phorest.book')
                ->legacyPlatform('phorest')
                ->displayName('Phorest')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('phorest.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('phorest.com')
                        ->path('#^/salon/(?<slug>[a-zA-Z0-9_-]{2,80})/?$#')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.phorest.com/salon/houseofbeautybarnsley'),
                )
                ->build(),
        ];
    }
}
