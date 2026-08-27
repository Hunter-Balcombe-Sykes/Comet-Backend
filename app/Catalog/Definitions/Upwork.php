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
 * Upwork — link-only freelancer profile (wave 2, 2026-08-28). Legacy
 * ~{hex} ids and vanity names (no hyphens, per Upwork's own rule) both live
 * under /freelancers/; the /freelancers/skills directory is excluded.
 */
class Upwork
{
    public static function brand(): Brand
    {
        return Brand::make('upwork', 'Upwork', 'https://www.upwork.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('upwork.profile')
                ->displayName('Upwork')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.upwork.com/freelancers/{handle}')
                ->detect(
                    Detector::url('upwork.com')
                        ->path('#^/freelancers/(?!skills(?:/|$))(?<handle>~?[A-Za-z0-9]{2,64})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
