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
 * SevenRooms — reservations, detect-only (logo card, no connect anywhere).
 *
 * Restaurant pages live at /reservations/<venue> — confirmed live (a real
 * slug 200s with the venue's name in the page title, e.g.
 * https://www.sevenrooms.com/reservations/cote is COTE Korean Steakhouse's
 * page; a made-up slug 404s). The also-documented /explore/<venue>/... form
 * could not be confirmed against real server-rendered content (client-side
 * app shell only) so it is deliberately left undetected rather than guessed.
 */
class Sevenrooms
{
    public static function brand(): Brand
    {
        return Brand::make('sevenrooms', 'SevenRooms', 'https://www.sevenrooms.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('sevenrooms.reserve')
                ->legacyPlatform('sevenrooms')
                ->displayName('SevenRooms')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('sevenrooms.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('sevenrooms.com')
                        ->path('#^/reservations/(?<venue>[a-z0-9-]+)/?$#i')
                        ->captures('venue')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.sevenrooms.com/reservations/cote'),
                )
                ->build(),
        ];
    }
}
