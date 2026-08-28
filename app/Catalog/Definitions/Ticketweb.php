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
 * TicketWeb — Ticketmaster's independent-venue arm, link-only (2026-08-28).
 * Three regional hosts, each its own registrable key, so each needs its own
 * detector line — .com/.co.uk/.ca are all live and all circulate in bios.
 */
class Ticketweb
{
    public static function brand(): Brand
    {
        return Brand::make('ticketweb', 'TicketWeb', 'https://www.ticketweb.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ticketweb.tickets')
                ->displayName('TicketWeb')
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
                    Detector::url('ticketweb.com')->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('ticketweb.co.uk')->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('ticketweb.ca')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
