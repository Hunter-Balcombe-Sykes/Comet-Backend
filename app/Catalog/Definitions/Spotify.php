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
                ->multiAccount(5)
                ->detect(
                    Detector::url('spotify.com')
                        ->subdomain('#^open$#')
                        ->path('#^/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(?<kind>artist|album|playlist|track|show|episode|user)/(?<id>[A-Za-z0-9]+)#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->build(),
        ];
    }
}
