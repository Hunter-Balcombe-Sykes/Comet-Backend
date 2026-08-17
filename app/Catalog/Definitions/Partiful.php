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
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://partiful.com/u/{handle}')
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
