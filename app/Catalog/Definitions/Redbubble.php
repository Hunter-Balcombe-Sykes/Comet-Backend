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
 * Redbubble — link-only artist shop (wave 2, 2026-08-28). The /people/
 * prefix keeps false positives low. Verified example:
 * redbubble.com/people/RobertMKAngel/shop (bot-403 but form-true).
 */
class Redbubble
{
    public static function brand(): Brand
    {
        return Brand::make('redbubble', 'Redbubble', 'https://www.redbubble.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('redbubble.shop')
                ->displayName('Redbubble')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.redbubble.com/people/{handle}/shop')
                ->detect(
                    Detector::url('redbubble.com')
                        ->path('#^/people/(?<handle>[A-Za-z0-9_.-]{2,40})(?:/shop)?/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
