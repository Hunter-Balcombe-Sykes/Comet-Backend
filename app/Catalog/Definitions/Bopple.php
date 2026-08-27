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
 * Bopple. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch. Two hosts verbatim from PRSP:463
 * (`~(^|\.)bopple\.(com|me|app)$~`).
 */
class Bopple
{
    public static function brand(): Brand
    {
        return Brand::make('bopple', 'Bopple', 'https://bopple.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bopple.order')
                ->legacyPlatform('bopple')
                ->displayName('Bopple')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('bopple.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('bopple.me')->strength(EvidenceStrength::ProfileLink),
                    // bopple.app: where EVERY real customer ordering URL
                    // actually lives (bopple.com is the B2B marketing site,
                    // .me 301s to it). The harvester learned this host in a
                    // prior fix and its comment flagged this detector as the
                    // remaining gap — closed plan-03 batch 9, 2026-08-27,
                    // verified against two real restaurants' indexed links.
                    Detector::url('bopple.app')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
