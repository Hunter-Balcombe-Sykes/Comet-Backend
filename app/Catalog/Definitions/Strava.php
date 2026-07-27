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
 * Strava — clubs only (athlete profiles are login-walled, per
 * StravaClubScraper's own comment, so never modelled here). refreshEvery has
 * no dedicated config('partna.refresh.intervals.strava') key and no
 * ->refreshEvery() call in PRSP — same unlisted-fallback shape as Pinterest
 * (see sidecar AMBIGUOUS #1); encoded as the real 24h default rather than 0,
 * since StravaFetch genuinely refreshes it on a schedule.
 */
class Strava
{
    public static function brand(): Brand
    {
        return Brand::make('strava', 'Strava', 'https://www.strava.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('strava.club')
                ->displayName('Strava')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Community)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(86400)
                ->note('refresh interval falls back to refresh.default_ttl_seconds — no dedicated config key exists (mirrors Pinterest, D2#7 in the inventory)')
                ->canonicalUrl('https://www.strava.com/clubs/{slug}')
                ->connect('connect.strava.url.v1')
                ->fetch('fetch.strava.scrape.v1')
                ->detect(
                    Detector::url('strava.com')
                        ->path('#^/clubs/(?<slug>[A-Za-z0-9_-]+)/?$#')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
