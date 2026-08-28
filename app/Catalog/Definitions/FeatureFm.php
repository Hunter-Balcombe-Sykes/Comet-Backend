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
 * Feature.fm — music smart links, link-only (2026-08-28). The Linkfire
 * shape, ffm.to being its short-link host. Also deliberately not an
 * expander host: one page, many destinations.
 */
class FeatureFm
{
    public static function brand(): Brand
    {
        return Brand::make('feature_fm', 'Feature.fm', 'https://www.feature.fm');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('feature_fm.release')
                ->displayName('Feature.fm')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('ffm.to')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('feature.fm')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
