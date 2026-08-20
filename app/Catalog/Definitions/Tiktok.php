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
 * TikTok — core-11 link social (UrlConnect + TiktokNormalizer). No scraping
 * (anti-bot), so the normalizer never bounds the handle's length — the
 * detector deliberately doesn't invent a cap the real grammar doesn't have.
 */
class Tiktok
{
    public static function brand(): Brand
    {
        return Brand::make('tiktok', 'TikTok', 'https://www.tiktok.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tiktok.profile')
                ->legacyPlatform('tiktok')
                ->displayName('TikTok')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.tiktok.com/@{handle}')
                ->connect('connect.tiktok.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('tiktok.com')
                        ->path('#^/@(?<handle>[A-Za-z0-9._]+)/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
