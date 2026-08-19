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
 * Squarespace commerce. Same shape as WooCommerce: a real Squarespace store
 * lives on the merchant's own domain and carries no distinguishing host
 * signal, so the only detector is the marketing host. The commerce probe
 * (§11) does the actual identifying — every Squarespace page answers
 * `?format=json`, and a products collection in that answer IS the store.
 */
class Squarespace
{
    public static function brand(): Brand
    {
        return Brand::make('squarespace', 'Squarespace', 'https://www.squarespace.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('squarespace.store')
                // F7 (2026-08-20): in lockstep with the shop family's
                // MAX_BRANDS (10, T9) — the catalog's default of 1 was
                // blocking Engine-1 store placements at ONE store while every
                // other door allowed ten (caught live: the046.com).
                ->multiAccount(10)
                ->displayName('Squarespace store')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('squarespace.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->note('listed so shop routing has a surface for probed Squarespace stores; own-domain storefronts carry no host signal — the commerce probe (§11) does the connecting')
                ->build(),
        ];
    }
}
