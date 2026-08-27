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
 * Tumblr — link-only social (wave 2, 2026-08-28). The legacy
 * {user}.tumblr.com subdomain form now 302s to the canonical path form, which
 * is the one detected. Verified example: tumblr.com/selinkilinc.
 */
class Tumblr
{
    public static function brand(): Brand
    {
        return Brand::make('tumblr', 'Tumblr', 'https://www.tumblr.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tumblr.profile')
                ->displayName('Tumblr')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.tumblr.com/{handle}')
                ->detect(
                    Detector::url('tumblr.com')
                        ->path('#^/(?<handle>[a-z0-9-]{3,32})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:tagged|search|explore|dashboard|settings|login|register|about|policy|apps|communities|art|jobs|press|support|tips)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
