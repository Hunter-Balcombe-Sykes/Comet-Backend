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
 * Partna's own reserved surfaces. The pseudo-bucket bridge surfaces
 * (partna.custom_link / manual_event / booking_link / reserve_link /
 * order_link) were DELETED 2026-08-19 with the pseudo-platform retirement —
 * zero live rows carried any of them, every routed link lands on its real
 * brand surface, and standalone events write events-pool items via
 * ManualEventWriter. partna.storefront is the shop-probe connection's
 * surface (real stores, brand identified per-probe in payload);
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
            $hidden('partna.manual_product', 'Manual product', RoutingClass::Shop, Shelf::Commerce)
                ->multiAccount(20)
                ->build(),
            // Legacy slug 'shop', not the 'partna' brand prefix — the one
            // surviving pseudo-bucket alias (the link-lane ones retired
            // 2026-08-19 and live on only in LegacyPlatformMap::RETIRED).
            $hidden('partna.storefront', 'Online store', RoutingClass::Shop, Shelf::Commerce)
                ->legacyPlatform('shop')
                ->build(),
        ];
    }
}
