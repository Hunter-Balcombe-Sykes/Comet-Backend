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
 * Vimeo — captures translated from VimeoApi::parseSource: a /channels/<name>
 * path (apiPath "channel/{name}") or a bare /<user> path (apiPath is the user
 * itself), excluding VimeoApi::RESERVED words and bare numeric ids (video
 * pages, not profiles).
 */
class Vimeo
{
    private const RESERVED = 'watch|upload|features|enterprise|pricing|blog|help|about|jobs|stats|search|categories|ondemand|settings|log_in|join|site_map|solutions|\d+';

    public static function brand(): Brand
    {
        return Brand::make('vimeo', 'Vimeo', 'https://vimeo.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('vimeo.account')
                ->legacyPlatform('vimeo')
                ->displayName('Vimeo')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Video)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(43200)
                ->connect('connect.vimeo.url.v1')
                ->fetch('fetch.vimeo.api.v1')
                ->multiAccount(10)
                ->detect(
                    Detector::url('vimeo.com')
                        ->path('#^/channels/(?<name>[A-Za-z0-9_-]+)/?$#')
                        ->captures('name')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('vimeo.com')
                        ->path('#^/(?!(?:'.self::RESERVED.')(?:/|$))(?<user>[A-Za-z0-9_-]+)/?$#')
                        ->captures('user')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
