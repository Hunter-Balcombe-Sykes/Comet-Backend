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
 * Strava — clubs only. Athlete profiles are login-walled and were never
 * modelled; StravaNormalizer refuses an athlete URL rather than coercing it
 * into a club link that would 404.
 *
 * refreshEvery is 0 as of the 2026-08-16 demotion (Phase 1.2). It used to
 * encode a 24h default because StravaFetch refreshed clubs on a schedule;
 * that fetch strategy and its scraper are deleted, so there is nothing to
 * refresh and no interval to fall back to.
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
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Community)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(0)
                ->note('link-only since 2026-08-16 (Phase 1.2): UrlConnect + StravaNormalizer, no fetch, no refresh')
                ->canonicalUrl('https://www.strava.com/clubs/{slug}')
                ->connect('connect.strava.url.v1')
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
