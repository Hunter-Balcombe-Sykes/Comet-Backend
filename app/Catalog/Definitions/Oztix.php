<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\IdentifierSource;
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
                    // Oztix serves one event under two shapes, and a paste can
                    // be either: the legacy `?Event=<numeric id>` query form
                    // still in circulation, which 302s to the modern
                    // `/outlet/event/<uuid>` path. Both are declared because
                    // the query form is what people have in their bios, and
                    // following the redirect would need a network call the
                    // projector deliberately cannot make.
                    Detector::url('oztix.com.au')
                        ->query('Event')
                        ->captures('Event')
                        ->from(IdentifierSource::Query)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://tickets.oztix.com.au/default.aspx?Event=246798 — verified live 2026-09-03 (302s to the /outlet/event/ form below)'),
                    Detector::url('oztix.com.au')
                        ->path('#^/outlet/event/(?<id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://tickets.oztix.com.au/outlet/event/bdbe320b-5e30-4a19-9651-b45c8ad7bc1b — verified live (HTTP 200) 2026-09-03'),
                )
                ->build(),
        ];
    }
}
