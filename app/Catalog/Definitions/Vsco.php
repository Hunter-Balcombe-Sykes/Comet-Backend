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
 * VSCO — link-only photo profile (wave 2, 2026-08-28). Shared links can
 * carry a REFERRER subdomain (arabella.vsco.co/leehahn/gallery — the owner is
 * the path segment, not the subdomain), which the path capture already gets
 * right. Verified example: vsco.co/sydsydney/gallery (bot-403 but form-true).
 */
class Vsco
{
    public static function brand(): Brand
    {
        return Brand::make('vsco', 'VSCO', 'https://vsco.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('vsco.profile')
                ->displayName('VSCO')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://vsco.co/{handle}/gallery')
                ->detect(
                    Detector::url('vsco.co')
                        ->path('#^/(?<handle>[a-z0-9_.-]{3,30})(?:/gallery|/collection(?:/\d+)?)?/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:discover|content|hub|features|learn|vsco-hub|about|pricing|safety|forum|store|search)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
