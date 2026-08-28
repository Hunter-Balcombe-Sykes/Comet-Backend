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
 * Abacus — hospitality POS, ordering and gift cards, link-only
 * (2026-08-28). Found on Bar Liberty as w.abacus.co/store/<id>/giftcards/…
 * The brand is migrating to nomni.ai per its own site notice; nomni.ai is
 * NOT listed here because no live link to it has been observed, and a
 * detector for a host we have never seen is a guess.
 */
class Abacus
{
    public static function brand(): Brand
    {
        return Brand::make('abacus', 'Abacus', 'https://abacus.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('abacus.order')
                ->displayName('Abacus')
                ->multiAccount(10)
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('abacus.co')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
