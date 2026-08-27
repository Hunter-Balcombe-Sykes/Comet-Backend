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
 * Rumble — link-only video channel (wave 2, 2026-08-28). Two parallel
 * grammars name the same entity: /c/{slug} (vanity) and /user/{name}.
 * Verified example: rumble.com/c/c-411334 (bot-403 but form-true).
 */
class Rumble
{
    public static function brand(): Brand
    {
        return Brand::make('rumble', 'Rumble', 'https://rumble.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('rumble.channel')
                ->displayName('Rumble')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Video)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://rumble.com/c/{handle}')
                ->detect(
                    Detector::url('rumble.com')
                        ->path('#^/(?:c|user)/(?<handle>[A-Za-z0-9_-]{2,60})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
