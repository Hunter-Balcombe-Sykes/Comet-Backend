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
 * Reddit — core-11 link social (UrlConnect + RedditNormalizer). The normalizer
 * accepts BOTH user profiles (u/, user/) and subreddits (r/) into the same
 * 'username' field — the detector mirrors that exact permissiveness rather
 * than restricting to profiles only.
 */
class Reddit
{
    public static function brand(): Brand
    {
        return Brand::make('reddit', 'Reddit', 'https://www.reddit.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('reddit.profile')
                ->legacyPlatform('reddit')
                ->displayName('Reddit')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.reddit.com/user/{handle}/')
                ->connect('connect.reddit.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('reddit.com')
                        ->path('#^/(?:u|user|r)/(?<handle>[A-Za-z0-9_-]{2,21})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
