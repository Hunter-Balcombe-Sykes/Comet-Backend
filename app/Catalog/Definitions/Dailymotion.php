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
 * Dailymotion — link-only video channel (wave 2, 2026-08-28). Both the
 * documented /user/{name} form and the bare handle form are captured; the
 * reject list carries the non-profile roots. SPA rendering blocked live
 * verification of the bare form — the /user/ form is the documented one.
 */
class Dailymotion
{
    public static function brand(): Brand
    {
        return Brand::make('dailymotion', 'Dailymotion', 'https://www.dailymotion.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('dailymotion.channel')
                ->displayName('Dailymotion')
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
                ->canonicalUrl('https://www.dailymotion.com/{handle}')
                ->detect(
                    Detector::url('dailymotion.com')
                        ->path('#^/(?:user/)?(?<handle>[A-Za-z0-9_-]{2,50})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:video|live|playlist|search|pro|legal|about|browse|press|jobs|partner|monetization)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
