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
 * See Tickets — ticketing, link-only (2026-08-28 cold-build round).
 * The Moshtix shape: a See Tickets link on a bio is somebody's event
 * listing, not the account holder's identity, so it detects at the host and
 * stays a MarketplaceListing. Only seetickets.com is listed — the US arm's
 * seetickets.us reportedly rebranded after the EVENTIM acquisition and was
 * NOT confirmed live, and an unverified host is a detector that can only
 * misfire.
 */
class SeeTickets
{
    public static function brand(): Brand
    {
        return Brand::make('see_tickets', 'See Tickets', 'https://www.seetickets.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('see_tickets.tickets')
                ->displayName('See Tickets')
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('seetickets.com')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
