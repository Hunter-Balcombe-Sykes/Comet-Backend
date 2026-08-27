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
 * Fiverr — link-only seller profile (wave 2, 2026-08-28). Root-level
 * namespace shared with many product surfaces — the reject list is
 * load-bearing and worth extending when a new Fiverr product ships.
 */
class Fiverr
{
    public static function brand(): Brand
    {
        return Brand::make('fiverr', 'Fiverr', 'https://www.fiverr.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('fiverr.profile')
                ->displayName('Fiverr')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.fiverr.com/{handle}')
                ->detect(
                    Detector::url('fiverr.com')
                        ->path('#^/(?<handle>[a-z0-9_]{3,30})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:sellers|categories|search|gig|gigs|users|business|pro|academy|community|support|login|join|about|terms|blog|resources|logo-maker|workspace|inspiration|guides|partnerships|press)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
