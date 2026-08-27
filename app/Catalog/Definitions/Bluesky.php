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
 * Bluesky — link-only social (wave 2, 2026-08-28). Handles are
 * {name}.bsky.social, a verbatim custom domain, or a did:plc: id, so the
 * capture charset is domain-shaped. Verified example: bsky.app/profile/pres.cafe.
 */
class Bluesky
{
    public static function brand(): Brand
    {
        return Brand::make('bluesky', 'Bluesky', 'https://bsky.app');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bluesky.profile')
                ->displayName('Bluesky')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://bsky.app/profile/{handle}')
                ->detect(
                    Detector::url('bsky.app')
                        ->path('#^/profile/(?<handle>[A-Za-z0-9:._-]{1,253})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
