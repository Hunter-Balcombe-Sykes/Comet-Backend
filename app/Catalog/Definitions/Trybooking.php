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

/** TryBooking — events/ticketing, detect-only. MarketplaceListing: a ticket seller, not a profile. */
class Trybooking
{
    public static function brand(): Brand
    {
        return Brand::make('trybooking', 'TryBooking', 'https://www.trybooking.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('trybooking.tickets')
                ->displayName('TryBooking')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(5)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('trybooking.com')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
