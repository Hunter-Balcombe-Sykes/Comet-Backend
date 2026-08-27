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

/** Ovatu — 27-set booking, detect-only (logo card, no connect anywhere). */
class Ovatu
{
    public static function brand(): Brand
    {
        return Brand::make('ovatu', 'Ovatu', 'https://ovatu.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ovatu.book')
                ->legacyPlatform('ovatu')
                ->displayName('Ovatu')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('ovatu.com')->strength(EvidenceStrength::ProfileLink),
                    // book.app: Ovatu's customer mini-site domain per their
                    // own docs ({business}.book.app). No live example was
                    // findable tonight (plan-03 batch 6 — both documented
                    // examples 302 to not-found), but the shape is theirs.
                    Detector::url('book.app')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
