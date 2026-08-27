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
 * Cameo — link-only talent profile (wave 2, 2026-08-28). Usernames churn
 * as talent leaves, so any single example is perishable; the grammar is the
 * platform's own documented one.
 */
class Cameo
{
    public static function brand(): Brand
    {
        return Brand::make('cameo', 'Cameo', 'https://www.cameo.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('cameo.profile')
                ->displayName('Cameo')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.cameo.com/{handle}')
                ->detect(
                    Detector::url('cameo.com')
                        ->path('#^/(?<handle>[a-z0-9_.-]{2,40})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:browse|business|about|enroll|login|careers|help|tags|categories|search|c|kids|blog)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
