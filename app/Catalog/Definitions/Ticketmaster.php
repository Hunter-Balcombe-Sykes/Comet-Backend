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
 * Ticketmaster — events/ticketing, detect-only, 21-TLD enumerated loop
 * (PRSP:461, verbatim). MarketplaceListing strength: a ticket seller, not a
 * profile. A second, older config('partna.social_platforms.ticketmaster')
 * entry exists with a single-domain + page-slug shape (superseded by this
 * registry entry) — not used here per the task's explicit "enumerated TLD
 * loops, plain host detectors" instruction. WLH.classify() independently
 * hardcodes ticketmaster.* to platform 'events-custom' (never this key) —
 * a pre-existing two-classifier disagreement (inventory D2#3), unrelated to
 * this catalog definition.
 */
class Ticketmaster
{
    /** @var list<string> */
    private const TLDS = ['com', 'com.au', 'co.uk', 'co.nz', 'ca', 'de', 'fr', 'es', 'it', 'nl', 'be', 'dk', 'se', 'no', 'fi', 'at', 'ch', 'ie', 'com.mx', 'sg', 'ae'];

    public static function brand(): Brand
    {
        return Brand::make('ticketmaster', 'Ticketmaster', 'https://www.ticketmaster.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ticketmaster.tickets')
                ->displayName('Ticketmaster')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(5)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("ticketmaster.{$tld}")->strength(EvidenceStrength::MarketplaceListing),
                        self::TLDS,
                    ),
                )
                ->build(),
        ];
    }
}
