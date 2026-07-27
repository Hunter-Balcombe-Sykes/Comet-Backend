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
 * Luma. New link-only surface. Config-registered
 * (config('partna.social_platforms').luma, config/partna.php:686-696) with
 * a bare lu.ma/<handle> path grammar treating the handle as the calendar/
 * profile page — translated verbatim here. NB WebsiteLinkHarvester's own
 * classify() (WebsiteLinkHarvester.php:379-388) treats a bare lu.ma/<slug>
 * as a single EVENT by default and only /user/<slug> as the organiser page
 * — a genuine tension with config's simpler grammar, flagged in the
 * sidecar report rather than silently picked one way.
 */
class Luma
{
    public static function brand(): Brand
    {
        return Brand::make('luma', 'Luma', 'https://lu.ma');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('luma.calendar')
                ->displayName('Luma')
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://lu.ma/{handle}')
                ->detect(
                    Detector::url('lu.ma')
                        ->path('#^/(?<handle>[a-zA-Z0-9-]{2,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
