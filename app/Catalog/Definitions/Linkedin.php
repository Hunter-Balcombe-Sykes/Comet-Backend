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
 * LinkedIn. Core-11 link-only social (UrlConnect + NORM/LinkedinNormalizer).
 * Captures the slug from any of /in|company|school|pub/<slug> — legacy
 * /pub/ profiles collapse to the modern /in/ form at connect time, not
 * detect time, so both are matched here. Unicode charset verbatim from
 * LinkedinNormalizer.php:33 (\p{L}\p{N}._- , 2-100 chars).
 */
class Linkedin
{
    public static function brand(): Brand
    {
        return Brand::make('linkedin', 'LinkedIn', 'https://www.linkedin.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('linkedin.profile')
                ->legacyPlatform('linkedin')
                ->displayName('LinkedIn')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.linkedin.com/in/{handle}/')
                ->connect('connect.linkedin.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('linkedin.com')
                        ->path('#^/(?:in|company|school|pub)/(?<handle>[\p{L}\p{N}._-]{2,100})/?$#u')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
