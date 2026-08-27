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
 * Deezer (wave 2, 2026-08-28) — music streaming with a public keyless JSON
 * API, so unlike most streamers it joined with a working content connector
 * (DeezerTracksConnector → listen pool) on day one. The artist page is the
 * connectable identity: deezer.com/artist/{id}, optionally behind a 2-letter
 * locale segment. Track/album/playlist links are deliberately not captured —
 * they are content, not an account.
 */
class Deezer
{
    public static function brand(): Brand
    {
        return Brand::make('deezer', 'Deezer', 'https://www.deezer.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('deezer.artist')
                ->displayName('Deezer')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::NumericId)
                ->refreshEvery(43200)
                ->canonicalUrl('https://www.deezer.com/artist/{id}')
                ->detect(
                    Detector::url('deezer.com')
                        ->path('#^(?:/[a-z]{2})?/artist/(?<id>\d{1,15})/?$#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
