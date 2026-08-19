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
 * Partiful — WLH-label events brand, new link-only surface with an explicit
 * routing override (Events, not the generic ".events" label-brand default
 * would already be Events anyway, but called out explicitly in the brief).
 * WLH.classify() collapses partiful.com to the generic 'events-custom'
 * bucket (host-only, no organiser/event split) — the organiser path capture
 * here instead comes from config('partna.social_platforms.partiful')
 * .url_path_extractor, a cleaner authoritative grammar for the /u/<handle>
 * organiser shape.
 */
class Partiful
{
    public static function brand(): Brand
    {
        return Brand::make('partiful', 'Partiful', 'https://partiful.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('partiful.events')
                ->displayName('Partiful')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(5)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://partiful.com/u/{handle}')
                // A single event page (/e/…) is an ITEM, never an organiser
                // account — the same Eventbrite reservedPaths('/e/') contract:
                // it projects no-rule-matched and seeds through the events
                // pool. Redundant today (the /u/ detector cannot match /e/)
                // and load-bearing the day anyone adds a looser detector.
                ->reservedPaths('/e/')
                ->detect(
                    Detector::url('partiful.com')
                        ->path('#^/u/(?<handle>[A-Za-z0-9-]{3,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
