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
 * WooCommerce. Picker-driven listing on the Commerce shelf: real Woo stores
 * are self-hosted on the merchant's own domain and carry no distinguishing
 * host signal, so the only detector is the marketing host. The commerce
 * probe (§11) does the actual connecting, via the dashboard store wizard.
 */
class Woocommerce
{
    public static function brand(): Brand
    {
        return Brand::make('woocommerce', 'WooCommerce', 'https://woocommerce.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('woocommerce.store')
                ->displayName('WooCommerce')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('woocommerce.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->note('listed so the picker offers WooCommerce; real Woo stores are self-hosted with no host signal — the commerce probe (§11) does the connecting, via the dashboard store wizard')
                ->build(),
        ];
    }
}
