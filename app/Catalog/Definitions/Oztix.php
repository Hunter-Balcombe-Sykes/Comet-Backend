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
 * Oztix — events/ticketing, detect-only. MarketplaceListing strength: a
 * ticket-seller host match is evidence of a listing, not a profile — the same
 * reasoning the task calls out explicitly for ticketmaster/ticketek, applied
 * uniformly across every ".tickets"-suffixed surface in this half.
 */
class Oztix
{
    public static function brand(): Brand
    {
        return Brand::make('oztix', 'Oztix', 'https://www.oztix.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('oztix.tickets')
                ->legacyPlatform('oztix')
                ->displayName('Oztix')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('oztix.com.au')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
