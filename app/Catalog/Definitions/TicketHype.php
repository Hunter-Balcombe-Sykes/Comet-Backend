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
 * TicketHype — Maltese event ticketing, link-only (2026-08-29, cold-build
 * round 4). Found on djrubyofficial, a Malta-based DJ.
 *
 * Small and regional, so it was checked rather than assumed: five distinct,
 * unrelated events were confirmed live on the domain (RONG Open Air,
 * Hypergroove, Hot Midnight, HIGHPHASE 01, Lollipop), each with its own
 * /<slug> checkout — a real multi-tenant ticketer, not one promoter's page.
 *
 * The Moshtix shape: a ticketing link is somebody's event listing, not the
 * account holder's identity, so it detects at the host and stays a
 * MarketplaceListing. tickethype.net is a separate self-hosted pretix install
 * under the same branding and is NOT listed — same brand, different product,
 * and no live link to it has been observed.
 */
class TicketHype
{
    public static function brand(): Brand
    {
        return Brand::make('tickethype', 'TicketHype', 'https://tickethype.com.mt');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tickethype.tickets')
                ->displayName('TicketHype')
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('tickethype.com.mt')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
