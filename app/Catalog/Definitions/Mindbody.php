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
 * Mindbody — 27-set booking, detect-only. Real host is mindbodyonline.com,
 * not mindbody.com (PRSP:467-470's HostMatch, verbatim).
 */
class Mindbody
{
    public static function brand(): Brand
    {
        return Brand::make('mindbody', 'Mindbody', 'https://www.mindbodyonline.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('mindbody.book')
                ->displayName('Mindbody')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('mindbodyonline.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
