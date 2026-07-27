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
 * SoundCloud — oEmbed music embed. Connect (SoundcloudConnect) accepts a
 * soundcloud.com path of 1-3 segments (profile/track/set); the detector
 * captures just the leading segment as the identifying username, tolerating
 * (not capturing) the trailing track/set segments.
 */
class Soundcloud
{
    public static function brand(): Brand
    {
        return Brand::make('soundcloud', 'SoundCloud', 'https://soundcloud.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('soundcloud.player')
                ->displayName('SoundCloud')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(43200)
                ->canonicalUrl('https://soundcloud.com/{username}')
                ->connect('connect.soundcloud.url.v1')
                ->fetch('fetch.soundcloud.oembed.v1')
                ->embed('https://w.soundcloud.com/player/?url={url}', 'fixed:166', [], false)
                ->multiAccount(5)
                ->detect(
                    Detector::url('soundcloud.com')
                        ->path('#^/(?<username>[a-z0-9_-]+)(?:/[a-z0-9_-]+){0,2}/?$#')
                        ->captures('username')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
