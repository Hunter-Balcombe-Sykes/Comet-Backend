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
 * YouTube Music — music.youtube.com is a Hosts.php ALIAS onto youtube.com, so
 * the detector lives on youtube.com itself with a subdomain constraint
 * (matches the 'music' subdomain specifically) plus the channel/browse id
 * path, per YoutubeScraper::channelIdFrom.
 */
class YoutubeMusic
{
    public static function brand(): Brand
    {
        return Brand::make('youtube_music', 'YouTube Music', 'https://music.youtube.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('youtube_music.channel')
                ->displayName('YouTube Music')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(43200)
                ->note('icon asset missing in design system — P6 item')
                ->canonicalUrl('https://music.youtube.com/channel/{id}')
                ->connect('connect.youtube_music.url.v1')
                ->fetch('fetch.youtube_music.scrape.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('youtube.com')
                        ->subdomain('#^music$#')
                        ->path('#^/(?:channel|browse)/(?<id>UC[A-Za-z0-9_-]{22})#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
