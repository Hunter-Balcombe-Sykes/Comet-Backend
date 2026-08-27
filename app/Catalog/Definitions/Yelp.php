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
 * Yelp — link-only business listing (wave 2, 2026-08-28). yelp.com.au
 * mirrors the GLOBAL index (a .com.au listing is not evidence of an AU
 * business). Verified example: yelp.com.au/biz/empire-barbershop-concord-2.
 */
class Yelp
{
    public static function brand(): Brand
    {
        return Brand::make('yelp', 'Yelp', 'https://www.yelp.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('yelp.listing')
                ->displayName('Yelp')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.yelp.com/biz/{handle}')
                ->detect(
                    Detector::url('yelp.com')
                        ->path('#^/biz/(?<handle>[a-z0-9-]+)/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('yelp.com.au')
                        ->path('#^/biz/(?<handle>[a-z0-9-]+)/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
