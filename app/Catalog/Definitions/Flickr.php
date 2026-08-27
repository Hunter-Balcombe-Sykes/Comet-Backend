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
 * Flickr — link-only photostream (wave 2, 2026-08-28). Ids may be an
 * alias or an NSID (44991205@N03), hence the @ in the charset; /photos/tags/
 * shares the first segment and is excluded by lookahead. Verified live
 * 2026-08-28.
 */
class Flickr
{
    public static function brand(): Brand
    {
        return Brand::make('flickr', 'Flickr', 'https://www.flickr.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('flickr.photos')
                ->displayName('Flickr')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Media)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.flickr.com/photos/{handle}')
                ->detect(
                    Detector::url('flickr.com')
                        ->path('#^/photos/(?!tags(?:/|$)|search(?:/|$))(?<handle>[A-Za-z0-9@._-]{2,60})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
