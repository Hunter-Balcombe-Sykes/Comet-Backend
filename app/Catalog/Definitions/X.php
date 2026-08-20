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
 * X (formerly Twitter). Exemplar definition: the detector's path capture is
 * the verbatim translation of NORM/XNormalizer's handle grammar + RESERVED
 * blocklist — one place instead of four (normalizer, config, harvester,
 * registry).
 */
class X
{
    private const RESERVED = 'i|home|explore|search|hashtag|intent|share|settings|notifications|messages|compose|login|signup';

    public static function brand(): Brand
    {
        return Brand::make('x', 'X', 'https://x.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('x.profile')
                ->legacyPlatform('x')
                ->displayName('X')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://x.com/{handle}')
                ->connect('connect.x.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('x.com')
                        ->path('#^/(?!(?:'.self::RESERVED.')(?:/|$))(?<handle>[A-Za-z0-9_]{1,15})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('twitter.com')
                        ->path('#^/(?!(?:'.self::RESERVED.')(?:/|$))(?<handle>[A-Za-z0-9_]{1,15})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->reservedPaths('/sharer', '/share', '/intent', '/dialog')
                ->build(),
        ];
    }
}
