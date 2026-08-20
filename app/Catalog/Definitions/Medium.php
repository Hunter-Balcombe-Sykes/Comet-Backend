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
 * Medium — core-11 link social (UrlConnect + MediumNormalizer). Shelved under
 * Media rather than Social (plan override for medium/substack), even though
 * LegacyPlatformMap routes it 'social'. WLH does not harvest medium.com at all
 * (absent from SOCIAL_HOSTS) — this detector is new for the catalog, translated
 * straight from MediumNormalizer's own handle grammar.
 */
class Medium
{
    public static function brand(): Brand
    {
        return Brand::make('medium', 'Medium', 'https://medium.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('medium.profile')
                ->legacyPlatform('medium')
                ->displayName('Medium')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Media)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://medium.com/@{handle}')
                ->connect('connect.medium.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('medium.com')
                        ->path('#^/@(?<handle>[A-Za-z0-9_.-]{2,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
