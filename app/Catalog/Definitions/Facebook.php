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
 * Facebook. Core-11 link-only social (UrlConnect + NORM/FacebookNormalizer).
 * The negative lookahead mirrors FacebookNormalizer::RESERVED_SEGMENTS
 * (pages/people/groups/pg, FacebookNormalizer.php:19) plus profile.php
 * (numeric id lives in the query string, not the path — a query-based
 * capture is out of scope for this idiom, so profile.php links are
 * deliberately excluded rather than mis-captured). Unlike X's normalizer,
 * Facebook's own vanity-URL branch has no charset check at all
 * (FacebookNormalizer.php:99-101 takes the raw segment); the capture
 * charset here is a reasonable invented bound, not a translated one.
 * reservedPaths mirrors WebsiteLinkHarvester::looksLikeProfile()'s facebook
 * branch verbatim (WebsiteLinkHarvester.php:490-493) — the sharer/share/
 * intent/dialog widget paths that are the classic false positives on
 * business sites.
 */
class Facebook
{
    private const RESERVED = 'pages|people|groups|pg|profile\.php';

    public static function brand(): Brand
    {
        return Brand::make('facebook', 'Facebook', 'https://www.facebook.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('facebook.profile')
                ->legacyPlatform('facebook')
                ->displayName('Facebook')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.facebook.com/{handle}')
                ->connect('connect.facebook.url.v1')
                ->detect(
                    Detector::url('facebook.com')
                        ->path('#^/(?!(?:'.self::RESERVED.')(?:/|$|\?))(?<handle>[A-Za-z0-9.]{1,100})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('fb.com')
                        ->path('#^/(?!(?:'.self::RESERVED.')(?:/|$|\?))(?<handle>[A-Za-z0-9.]{1,100})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->reservedPaths('/sharer', '/share', '/intent', '/dialog')
                ->build(),
        ];
    }
}
