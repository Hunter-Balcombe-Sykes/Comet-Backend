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
 * Shopify storefronts. Restores the detection WebsiteLinkHarvester's
 * SHOP_HOSTS carried ('myshopify.com' IS decisively a storefront) which the
 * first catalog pass dropped. `myshopify.com` is a suffix override, so the
 * canonicaliser hands the tenant label through as the subdomain and this
 * detector captures it as the store identifier.
 *
 * A merchant's OWN domain (their real storefront host) carries no
 * distinguishing host signal — that stays the commerce probe's job (§11's
 * five keyless probes), not a detector's.
 */
class Shopify
{
    public static function brand(): Brand
    {
        return Brand::make('shopify', 'Shopify', 'https://www.shopify.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('shopify.store')
                // F7 (2026-08-20): in lockstep with the shop family's
                // MAX_BRANDS (10, T9) — the catalog's default of 1 was
                // blocking Engine-1 store placements at ONE store while every
                // other door allowed ten (caught live: the046.com).
                ->multiAccount(10)
                ->displayName('Shopify store')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Domain)
                ->refreshEvery(0)
                ->canonicalUrl('https://{store}.myshopify.com')
                ->detect(
                    Detector::url('myshopify.com')
                        ->subdomain('#^(?!www$)(?<store>[a-z0-9][a-z0-9-]{1,60})$#')
                        ->captures('store')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->note('listed in the picker; the dashboard hands shop-class picks to the store wizard / commerce probe (§11) — own-domain storefronts still carry no host signal')
                ->build(),
        ];
    }
}
