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
 * Ko-fi. Registered (PRSP:134, linkOnly) with zero connect wiring and
 * absent from WebsiteLinkHarvester entirely (inventory D2 #5). Surface key
 * is 'ko_fi.page' (underscore) per LegacyPlatformMap even though the legacy
 * slug and real domain both use a hyphen ('ko-fi', ko-fi.com).
 */
class KoFi
{
    public static function brand(): Brand
    {
        return Brand::make('ko_fi', 'Ko-fi', 'https://ko-fi.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ko_fi.page')
                ->legacyPlatform('ko-fi')
                ->displayName('Ko-fi')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://ko-fi.com/{handle}')
                ->detect(
                    Detector::url('ko-fi.com')
                        ->path('#^/(?<handle>[A-Za-z0-9_-]{2,64})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:about|explore|gold|shop|commissions|manage|account|login|signup|faq|pricing|help|discover|blog|stream|memberships|posts|settings)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('ko-fi.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
