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
                ->detect(
                    Detector::url('mryum.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('meandu.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
