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
 * Spotify — oEmbed music embed. SpotifyConnect (Deferred) resolves any
 * open.spotify.com entity link; the {kind, id} pair together is the real
 * identity (a bare id collides across kinds), hence Composite.
 *
 * T6b (2026-08-20): the detector narrows to ACCOUNT kinds — artist, show,
 * user, playlist. track/album/episode are ITEMS and belong in the
 * watch/listen pools (MediaPageReader's grammar claims them; the scan lanes
 * seed them via MediaSeeder) — the pre-pools "embed any Spotify URL" design
 * auto-connected a bio's podcast EPISODE link as a "Spotify" platform at
 * confidence 99 (natalieannehair, 2026-08-19). Playlist KEPT as connectable
 * (owner-flagged decision): a playlist is a curated collection — closer to
 * an account than an item — and no grammar can make it a pool item.
 */
class Spotify
{
    public static function brand(): Brand
    {
        return Brand::make('spotify', 'Spotify', 'https://www.spotify.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('spotify.player')
                ->legacyPlatform('spotify')
                ->displayName('Spotify')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Composite)
                ->refreshEvery(43200)
                ->canonicalUrl('https://open.spotify.com/{kind}/{id}')
                ->connect('connect.spotify.url.v1')
                ->fetch('fetch.spotify.oembed.v1')
                ->embed(
                    'https://open.spotify.com/embed/{entity_path}',
                    'ratio:wide',
                    ['autoplay', 'clipboard-write', 'encrypted-media', 'fullscreen', 'picture-in-picture'],
                    false,
                )
                ->multiAccount(10)
                ->detect(
                    Detector::url('spotify.com')
                        ->subdomain('#^open$#')
                        // `show` left for spotify_podcasts.show (Item 11f,
                        // 2026-09-01): a show is an account-shaped podcast
                        // source with its own brand/lane now, not a player
                        // embed. Existing show-kind player rows keep working
                        // (stored payloads are never re-detected); only new
                        // routing/connects move.
                        ->path('#^/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(?<kind>artist|playlist|user)/(?<id>[A-Za-z0-9]+)#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->build(),
        ];
    }
}
