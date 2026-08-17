<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\Lifecycle;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * The booking page we can reach but cannot name — nearly always the business's
 * OWN site (`fadelab.com.au/book-appointment`).
 *
 * Owner ruling 2026-08-16, taken after convergence Phase 6's first cut dropped
 * these. Google Business surfaces a "Book online" link that lives on the
 * merchant's own domain, and `GoogleBusinessAutoSync` deliberately keeps it —
 * there is a test whose comment reads "It must NOT be filtered as the website
 * echo — it's a real way to book." Retiring `partna.booking_link` with nowhere
 * for it to go turned a working Book button into an empty Booking card, which
 * is a product regression rather than a cleanup.
 *
 * NOT a reintroduction of `partna.booking_link`, and the difference is the
 * whole point of the phase. That key held EVERY booking link — Booksy,
 * Treatwell, Calendly and the merchant's own page, all indistinguishable. This
 * one holds only what no brand claims: every one of the 18 booking hosts the
 * harvester classifies has its own surface and reaches it first.
 *
 * Its own BRAND (`direct`), not `generic`: the public allowlist is keyed by
 * brand prefix, and `generic` is the storefront one, which publishes `[]` —
 * products reach the wire through `profile.pools.shop`. A booking card needs
 * url/name/favicon/logo/provider. One brand cannot answer both.
 *
 * No detector, deliberately — a detector claims "this host IS this brand", and
 * the defining property here is that no host pattern identifies it. Reached only
 * as the last arm of a classification that found nothing else.
 */
class DirectBooking
{
    public static function brand(): Brand
    {
        return Brand::make('direct', 'Booking page');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('direct.book')
                ->displayName('Booking page')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->lifecycle(Lifecycle::Hidden)
                ->note("the booking page no brand claims — usually the business's own site; last arm only, after every real brand has been tried")
                ->build(),
        ];
    }
}
