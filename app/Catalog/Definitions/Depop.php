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
 * Depop — link-only shop profile (wave 2, 2026-08-28). The flat namespace
 * is shared with search/campaign paths (/products/…, /theme/melbourne/) that
 * are structurally identical to usernames — the reject list is load-bearing.
 * Verified example: depop.com/gingerthing/ (bot-403 but form-true).
 */
class Depop
{
    public static function brand(): Brand
    {
        return Brand::make('depop', 'Depop', 'https://www.depop.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('depop.shop')
                ->displayName('Depop')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.depop.com/{handle}/')
                ->detect(
                    Detector::url('depop.com')
                        ->path('#^/(?<handle>[a-z0-9_.-]{3,30})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:products|theme|search|help|sell|brands|category|categories|login|signup|blog|about|careers|terms|privacy|explore|stories|sitemap|accessibility)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
