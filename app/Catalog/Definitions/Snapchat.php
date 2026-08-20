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

/** Snapchat — core-11 link social (UrlConnect + SnapchatNormalizer). */
class Snapchat
{
    public static function brand(): Brand
    {
        return Brand::make('snapchat', 'Snapchat', 'https://www.snapchat.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('snapchat.profile')
                ->legacyPlatform('snapchat')
                ->displayName('Snapchat')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://snapchat.com/add/{handle}')
                ->connect('connect.snapchat.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('snapchat.com')
                        ->path('#^/add/(?<handle>[A-Za-z0-9._-]{3,15})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
