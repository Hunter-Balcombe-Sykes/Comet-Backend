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
 * EASI. One of the 27-provider stopgap set (PICR.php:178-222) — detect-only
 * card, no connect/fetch. Four hosts verbatim from PRSP:477-480's regex
 * (`~(^|\.)easi(global)?\.com(\.au)?$~`): easi.com, easi.com.au,
 * easiglobal.com, easiglobal.com.au.
 */
class Easi
{
    public static function brand(): Brand
    {
        return Brand::make('easi', 'EASI', 'https://www.easi.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('easi.order')
                ->legacyPlatform('easi')
                ->displayName('EASI')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('easi.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('easi.com.au')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('easiglobal.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('easiglobal.com.au')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
