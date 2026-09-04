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
 * HotDoc (T27a, 2026-08-28) — AU GP/medical bookings. Link-only. Practice
 * booking pages are /medical-centres/<suburb-state-postcode>/<practice
 * slug>/doctors (confirmed live, batch T27b).
 */
class Hotdoc
{
    public static function brand(): Brand
    {
        return Brand::make('hotdoc', 'HotDoc', 'https://www.hotdoc.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('hotdoc.book')
                ->displayName('HotDoc')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('hotdoc.com.au')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('hotdoc.com.au')
                        ->path('#^/medical-centres/[a-z0-9-]+/(?<slug>[a-z0-9-]+)/doctors#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.hotdoc.com.au/medical-centres/mulgrave-VIC-3170/mckinley-medical-centre/doctors'),
                )
                ->build(),
        ];
    }
}
