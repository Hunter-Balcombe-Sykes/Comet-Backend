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
 * Twitch — login capture + RESERVED blocklist verbatim from TwitchScraper
 * (X.php technique). Embed binds the sitepage's own host into `parent`
 * (Twitch's embed requirement), hence bindsHost=true.
 */
class Twitch
{
    private const RESERVED = 'directory|downloads|jobs|turbo|settings|subscriptions|wallet|drops|search|videos|p|login|signup|friends';

    public static function brand(): Brand
    {
        return Brand::make('twitch', 'Twitch', 'https://www.twitch.tv');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('twitch.channel')
                ->displayName('Twitch')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Video)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.twitch.tv/{login}')
                ->connect('connect.twitch.url.v1')
                ->embed('https://player.twitch.tv/?channel={login}&parent={host}', 'ratio:wide', ['autoplay', 'fullscreen'], true)
                ->multiAccount(5)
                ->detect(
                    Detector::url('twitch.tv')
                        ->path('#^/(?!(?:'.self::RESERVED.')(?:/|$))(?<login>[A-Za-z0-9_]{3,25})/?$#')
                        ->captures('login')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
