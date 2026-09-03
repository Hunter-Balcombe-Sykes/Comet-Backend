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
 * Resy — reservations, detect-only (logo card, no connect anywhere).
 *
 * Restaurant pages live at /cities/<city>/venues/<slug> — confirmed live
 * (e.g. https://resy.com/cities/new-york-ny/venues/carbone renders Carbone's
 * real reservation widget). The shorter /cities/<city>/<slug> form (no
 * /venues/) 404s — it is NOT a valid shape — and /cities/<city> alone is the
 * city browse page, so the /venues/ segment is the load-bearing anchor.
 */
class Resy
{
    public static function brand(): Brand
    {
        return Brand::make('resy', 'Resy', 'https://resy.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('resy.reserve')
                ->legacyPlatform('resy')
                ->displayName('Resy')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('resy.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('resy.com')
                        ->path('#^/cities/[^/]+/venues/(?<venue>[^/?]+)#i')
                        ->captures('venue')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://resy.com/cities/new-york-ny/venues/carbone'),
                )
                ->build(),
        ];
    }
}
