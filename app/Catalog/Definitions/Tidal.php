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
 * Tidal — keyless embed, same dormant shape as Mixcloud: "Detect: none,
 * Connect: none registered". Explicitly not refreshable.
 */
class Tidal
{
    public static function brand(): Brand
    {
        return Brand::make('tidal', 'Tidal', 'https://tidal.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tidal.player')
                ->displayName('Tidal')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->note('dormant until embed set expands, §10')
                ->embed('https://embed.tidal.com/{entity_path}', 'ratio:wide', [], false)
                // Connectable as an artist link (task #17, 2026-08-18):
                // tidal.com/browse/artist/{id} or tidal.com/artist/{id}.
                ->canonicalUrl('https://tidal.com/browse/artist/{handle}')
                ->detect(
                    Detector::url('tidal.com')
                        ->path('#^/(?:browse/)?artist/(?<handle>\d+)/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('tidal.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
