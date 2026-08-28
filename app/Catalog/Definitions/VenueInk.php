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
 * Venue Ink — tattoo-artist booking, link-only (2026-08-28). Found live on
 * fayeellefineline, whose only bio link was venue.ink/@<handle> and which
 * classified as nothing in either lane, so the artist's booking page was
 * dropped entirely. Host-level: the @handle path is theirs, but this brand
 * has no connect capability, so there is nothing to capture it for.
 */
class VenueInk
{
    public static function brand(): Brand
    {
        return Brand::make('venue_ink', 'Venue Ink', 'https://venue.ink');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('venue_ink.book')
                ->displayName('Venue Ink')
                ->multiAccount(10)
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('venue.ink')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
