<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\Lifecycle;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Genbook. New link-only surface — today a
 * WebsiteLinkHarvester::BOOKING_HOSTS label (WebsiteLinkHarvester.php:118)
 * that collapses into the generic 'booking' pseudo-bucket; this surface is
 * the P1 upgrade to a first-class brand. Host-only detector, no capture.
 */
class Genbook
{
    public static function brand(): Brand
    {
        return Brand::make('genbook', 'Genbook', 'https://www.genbook.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('genbook.book')
                ->displayName('Genbook')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                // RETIRED 2026-09-03: Booksy acquired Genbook on 2021-09-07 and
                // sunset it — genbook.com now 301s to booksy.com/biz, so every
                // genbook.com link a harvest could find is a redirect to a
                // different brand's marketing page, never a bookable profile.
                // This is the failure mode a status check cannot catch: the
                // redirect resolves 200, so "the page loads" proves nothing.
                ->lifecycle(Lifecycle::Retired)
                ->detect(
                    Detector::url('genbook.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
