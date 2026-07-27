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
 * Partna's own reserved surfaces — the structural replacement for the pseudo
 * platforms (plan §1). The three *_link surfaces exist so legacy pseudo-bucket
 * rows keep a valid surface (aliasing back verbatim to booking/reservations/
 * online-ordering) until P2's reproject upgrades them to real brand surfaces;
 * they are Hidden, never in pickers. partna.storefront is the shop-probe
 * connection's surface (real stores, brand identified per-probe in payload);
 * partna.manual_product is the manual product add-path (§16), dormant until P4.
 */
class Partna
{
    public static function brand(): Brand
    {
        return Brand::make('partna', 'Partna', 'https://partna.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        $hidden = fn (string $key, string $name, RoutingClass $class, Shelf $shelf) => SurfaceBuilder::for($key)
            ->displayName($name)
            ->routing($class)
            ->shelf($shelf)
            ->identifier(IdentifierKind::Url)
            ->refreshEvery(0)
            ->notConnectable()
            ->lifecycle(Lifecycle::Hidden);

        return [
            $hidden('partna.custom_link', 'Custom link', RoutingClass::Link, Shelf::Social)
                ->multiAccount(20)
                ->build(),
            $hidden('partna.manual_event', 'Custom event', RoutingClass::Events, Shelf::Events)
                ->multiAccount(20)
                ->build(),
            $hidden('partna.manual_product', 'Manual product', RoutingClass::Shop, Shelf::Commerce)
                ->multiAccount(20)
                ->build(),
            $hidden('partna.storefront', 'Online store', RoutingClass::Shop, Shelf::Commerce)
                ->build(),
            $hidden('partna.booking_link', 'Booking link', RoutingClass::Booking, Shelf::Booking)
                ->build(),
            $hidden('partna.reserve_link', 'Reservation link', RoutingClass::Reservations, Shelf::Food)
                ->build(),
            $hidden('partna.order_link', 'Ordering link', RoutingClass::Ordering, Shelf::Food)
                ->build(),
        ];
    }
}
