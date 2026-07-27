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
 * Bandcamp. The one brand in this file's half with a bespoke
 * ConnectStrategy (CONN/BandcampConnect, not UrlConnect) — its identifier
 * lives in the SUBDOMAIN (artist.bandcamp.com), verbatim from
 * BandcampScraper::normalizeOrigin() (BandcampScraper.php:17-30), so the
 * detector captures from IdentifierSource::Subdomain rather than Path.
 * Also has a highlights strategy (BandcampHighlights) — the only brand in
 * this half with all three of connect/fetch/highlights capabilities.
 */
class Bandcamp
{
    public static function brand(): Brand
    {
        return Brand::make('bandcamp', 'Bandcamp', 'https://bandcamp.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bandcamp.artist')
                ->displayName('Bandcamp')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(43200)
                ->canonicalUrl('https://{handle}.bandcamp.com')
                ->connect('connect.bandcamp.url.v1')
                ->fetch('fetch.bandcamp.scrape.v1')
                ->highlights('highlights.bandcamp.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('bandcamp.com')
                        ->subdomain('#^(?<handle>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
