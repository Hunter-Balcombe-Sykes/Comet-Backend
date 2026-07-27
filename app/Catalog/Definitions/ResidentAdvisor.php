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
 * Resident Advisor — events/ticketing, detect-only. Real host is ra.co, a
 * different domain entirely from the brand name (PRSP:457's HostMatch,
 * verbatim). MarketplaceListing: a ticket seller, not a profile.
 */
class ResidentAdvisor
{
    public static function brand(): Brand
    {
        return Brand::make('resident_advisor', 'Resident Advisor', 'https://ra.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('resident_advisor.tickets')
                ->displayName('Resident Advisor')
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('ra.co')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
