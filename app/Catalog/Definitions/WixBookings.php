<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Wix Bookings (T27a, 2026-08-28). Deliberately NARROW detector: most Wix
 * sites live on custom domains (undetectable), and a bare wixsite.com link
 * is a whole WEBSITE, not a booking page — classifying it as booking would
 * be wrong more often than right. bookings.wixapps.net is the shape Wix's
 * own booking share links use. Link-only.
 */
class WixBookings
{
    public static function brand(): Brand
    {
        return Brand::make('wix_bookings', 'Wix Bookings', 'https://www.wix.com/bookings');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('wix_bookings.book')
                ->displayName('Wix Bookings')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                // Detect-only: path-filtered detectors can't back a sane manual
                // connect card (BrandCoverageTest's 422 rule) — Wix booking links
                // route automatically from scanned bios; there is no card to type
                // one into.
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('wixapps.net')->path('#/(bookings|book-online)(/|$)#')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('wixsite.com')->path('#/(bookings|book-online)(/|$)#')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
