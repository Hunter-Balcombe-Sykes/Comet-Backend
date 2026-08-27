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
 * Bark — link-only company profile (wave 2, 2026-08-28). The trailing id
 * is an opaque token (numeric on live pages, base36-ish in search snapshots)
 * so it rides unvalidated. Verified example:
 * bark.com/en/gb/company/startup-website-co/2369176/.
 */
class Bark
{
    public static function brand(): Brand
    {
        return Brand::make('bark', 'Bark', 'https://www.bark.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bark.company')
                ->displayName('Bark')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('bark.com')
                        ->path('#^/en/[a-z]{2}/company/(?<slug>[a-z0-9-]+)(?:/[A-Za-z0-9]+)?/?$#')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
