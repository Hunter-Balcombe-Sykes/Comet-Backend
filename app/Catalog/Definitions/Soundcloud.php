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
 * SoundCloud — oEmbed music embed. Connect (SoundcloudConnect) still accepts
 * a soundcloud.com path of 1-3 segments (a user pasting a track into the
 * connect flow resolves its profile — user-initiated, fine).
 *
 * T6b (2026-08-20): the DETECTOR narrows to the single-segment PROFILE
 * shape. A /user/track URL is an ITEM — MediaPageReader's grammar claims it
 * and the scan lanes seed a Listen item — and auto-connecting the profile
 * off a track link is the same bug class as the Spotify episode
 * (natalieannehair, 2026-08-19). Site chrome single segments are excluded
 * with the same reserved list MediaPageReader::accountPlatformLabel uses.
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
                        ->path('#^/(?!(?:discover|search|upload|stream|library|charts|feed|you|messages|notifications|settings|pro|premium|mobile|imprint|terms-of-use|jobs|blog|pages)(?:/|$))(?<username>[a-z0-9_-]+)/?$#')
                        ->captures('username')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
