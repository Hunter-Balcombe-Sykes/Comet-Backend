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
 * Songkick — link-only artist events page (wave 2, 2026-08-28).
 * /artists/{id}-{slug}. Verified example: songkick.com/artists/276130-acdc
 * (live 200).
 */
class Songkick
{
    public static function brand(): Brand
    {
        return Brand::make('songkick', 'Songkick', 'https://www.songkick.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('songkick.artist')
                ->displayName('Songkick')
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.songkick.com/artists/{id}')
                ->detect(
                    Detector::url('songkick.com')
                        ->path('#^/artists/(?<id>\d{1,12})(?:-[a-z0-9-]+)?/?$#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
