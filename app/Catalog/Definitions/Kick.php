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
 * Kick. Core-11 link-only social (UrlConnect + NORM/KickNormalizer) — a
 * simple channel handle, no reserved-segment blocklist needed
 * (KickNormalizer.php:20-34 has none). Also registered as a streaming
 * live-poll platform via config('partna.streaming_platforms') (CFG:254) —
 * unrelated to this catalog surface, noted for cross-reference only.
 */
class Kick
{
    public static function brand(): Brand
    {
        return Brand::make('kick', 'Kick', 'https://kick.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('kick.channel')
                ->legacyPlatform('kick')
                ->displayName('Kick')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://kick.com/{handle}')
                ->connect('connect.kick.url.v1')
                ->detect(
                    Detector::url('kick.com')
                        ->path('#^/(?<handle>[A-Za-z0-9_-]{3,50})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->note("also registered as a streaming live-poll platform via config('partna.streaming_platforms')")
                ->build(),
        ];
    }
}
