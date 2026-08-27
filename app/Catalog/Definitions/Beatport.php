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
 * Beatport — link-only artist page (wave 2, 2026-08-28). /artist/{slug}/{id}
 * with a numeric id; genre/chart/release/label paths never match. Verified
 * example: beatport.com/artist/johnny-rico/485532 (bot-403 but form-true).
 */
class Beatport
{
    public static function brand(): Brand
    {
        return Brand::make('beatport', 'Beatport', 'https://www.beatport.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('beatport.artist')
                ->displayName('Beatport')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('beatport.com')
                        ->path('#^/artist/[a-z0-9-]+/(?<id>\d{1,12})(?:/tracks)?/?$#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
