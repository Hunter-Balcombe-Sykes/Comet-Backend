<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\Lifecycle;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Mr Yum / me&u (T27a, 2026-08-28) — AU table-ordering menus
 * (mryum.com/<venue>; meandu.com after the merge). Link-only.
 */
class MrYum
{
    public static function brand(): Brand
    {
        return Brand::make('mr_yum', 'Mr Yum', 'https://www.mryum.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('mr_yum.order')
                ->displayName('Mr Yum')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                // RETIRED 2026-09-03: merged into me&u on 2023-11-29 and the brand
                // was retired — mryum.com now 301s to meandu.com. Same trap as
                // Genbook: the redirect resolves 200, so a liveness check would
                // call a dead venue link healthy. The meandu.com detector below
                // is the live successor and stays.
                ->lifecycle(Lifecycle::Retired)
                ->detect(
                    Detector::url('mryum.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('meandu.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
