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
 * Venmo — link-only payment profile (wave 2, 2026-08-28). Personal
 * profiles live under /u/; bare-root business handles collide with every
 * other Venmo route and are deliberately not captured. Verified live:
 * venmo.com/u/{name} resolves for real profiles.
 */
class Venmo
{
    public static function brand(): Brand
    {
        return Brand::make('venmo', 'Venmo', 'https://venmo.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('venmo.profile')
                ->displayName('Venmo')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://venmo.com/u/{handle}')
                ->detect(
                    Detector::url('venmo.com')
                        ->path('#^/u/(?<handle>[A-Za-z0-9_-]{3,30})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
