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
 * Eventim (CTS Eventim) — Europe's largest ticketer, link-only (2026-08-28).
 * eventim.de and eventim.us both turned up in the triage queue. Six regional
 * hosts listed, each CONFIRMED live; the group's sibling brands (TicketOne,
 * Entradas, oeticket) are separate companies with separate domains and are
 * deliberately NOT folded in here — one brand, one set of hosts.
 */
class Eventim
{
    public static function brand(): Brand
    {
        return Brand::make('eventim', 'Eventim', 'https://www.eventim.de');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('eventim.tickets')
                ->displayName('Eventim')
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
                    Detector::url('eventim.de')->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('eventim.com')->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('eventim.co.uk')->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('eventim.fr')->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('eventim.nl')->strength(EvidenceStrength::MarketplaceListing),
                    Detector::url('eventim.pl')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
