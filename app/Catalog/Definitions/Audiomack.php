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
 * Audiomack — link-only artist page (wave 2, 2026-08-28). Flat namespace
 * (audiomack.com/{artist}) — the reject list carries the known non-profile
 * roots. Verified example: audiomack.com/rod-wave (live 200).
 */
class Audiomack
{
    public static function brand(): Brand
    {
        return Brand::make('audiomack', 'Audiomack', 'https://audiomack.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('audiomack.artist')
                ->displayName('Audiomack')
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
                ->canonicalUrl('https://audiomack.com/{handle}')
                ->detect(
                    Detector::url('audiomack.com')
                        ->path('#^/(?<handle>[a-z0-9._-]{2,60})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:world|charts|data-api|trending|genre|song|album|playlist|search|about|legal|monetization|creators|premium|edit|login|join)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
