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
 * Spotify Podcasts (Item 11f, 2026-09-01) — Apple Podcasts' sibling: a SHOW
 * on open.spotify.com/show/<id>, connected as its own brand rather than a
 * spotify.player selection because a show is an account-shaped source
 * (episodes flow through the ingest lane into the listen pool) while the
 * player surface holds artist/album/track/playlist embeds. A track/album/
 * episode link stays an ITEM (T6b) and never lands here.
 *
 * A DISTINCT brand from 'spotify' on purpose: one brand cannot carry two
 * surfaces whose slugs both derive from its prefix, and spotify.player's
 * detector already claims open.spotify.com's other kinds. The slug is the
 * brand prefix ('spotify_podcasts' — no legacyPlatform call), so the
 * generated platform column's split_part ELSE covers it with no migration.
 *
 * The show-id charset is Spotify's base-62; /intl-xx/ prefixes appear on
 * regional share links and are dropped by SpotifyPodcastsScraper::showId,
 * which owns the same grammar for the connect strategy — the detector here
 * mirrors it (path-only, id captured) so a scanned bio link and a pasted
 * connect input can never disagree about what a show link is.
 */
class SpotifyPodcasts
{
    public static function brand(): Brand
    {
        return Brand::make('spotify_podcasts', 'Spotify Podcasts', 'https://open.spotify.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('spotify_podcasts.show')
                ->displayName('Spotify Podcasts')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Podcast)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(604800)
                ->canonicalUrl('https://open.spotify.com/show/{id}')
                ->connect('connect.spotify_podcasts.url.v1')
                ->fetch('fetch.spotify_podcasts.scrape.v1')
                ->multiAccount(10)
                ->detect(
                    // Keyed the same way Spotify.php's player detector is
                    // (registrable domain + subdomain constraint) — a
                    // full-host key is never looked up by the router.
                    Detector::url('spotify.com')
                        ->subdomain('#^open$#')
                        ->path('#^/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?show/(?<id>[A-Za-z0-9]+)#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->build(),
        ];
    }
}
