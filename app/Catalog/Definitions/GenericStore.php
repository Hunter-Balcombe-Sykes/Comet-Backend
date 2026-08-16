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
 * The storefront we can read but cannot name.
 *
 * `ShopProviderDetector` resolves five providers. Four are real platforms with
 * their own catalog surfaces (shopify.store, woocommerce.store,
 * squarespace.store, bigcartel.store); the fifth, `generic`, is any page
 * carrying standard Product JSON-LD. That is a real, connectable, re-scrapeable
 * store — the connect endpoint advertises it ("pages with standard product
 * markup") — and convergence Phase 6, which gives every store its own
 * connection, needs somewhere to put it.
 *
 * NOT a reintroduction of `partna.storefront` under a new name, and the
 * difference is the whole point of the phase. That surface held EVERY
 * storefront: five Shopify stores and a WooCommerce one sat behind one row, so
 * ingest could not tell them apart and no per-brand feature could ever reach
 * them. This one holds only the stores whose platform genuinely has no name —
 * zero of them on dev, where all 9 brands are shopify (8) or woocommerce (1).
 *
 * Hidden and notConnectable for the same reason bigcartel.store is: connecting
 * happens through the commerce probe and ShopController's own detection, never
 * through a catalog connect capability or a picker.
 *
 * No detector, deliberately. A detector claims "this host IS this brand", and
 * the defining property here is that no host pattern identifies it —
 * WebsiteLinkHarvester must keep answering null so the commerce probe runs.
 */
class GenericStore
{
    public static function brand(): Brand
    {
        return Brand::make('generic', 'Online store');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('generic.store')
                ->displayName('Online store')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->multiAccount(5)
                ->notConnectable()
                ->lifecycle(Lifecycle::Hidden)
                ->note('the storefront whose platform has no name — Product JSON-LD only; connects through ShopController detection, never a picker')
                ->build(),
        ];
    }
}
