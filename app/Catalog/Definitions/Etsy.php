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
 * Etsy — link-only storefront (wave 2, 2026-08-28). /shop/{Name} with an
 * optional locale prefix; the separate /people/ member namespace is
 * deliberately not captured (a buyer account is not a storefront).
 * Verified example: etsy.com/shop/HandmadeByCatkin (bot-403 but form-true).
 */
class Etsy
{
    public static function brand(): Brand
    {
        return Brand::make('etsy', 'Etsy', 'https://www.etsy.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('etsy.shop')
                ->displayName('Etsy')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.etsy.com/shop/{handle}')
                ->detect(
                    Detector::url('etsy.com')
                        ->path('#^(?:/[a-z]{2})?/shop/(?<handle>[A-Za-z0-9]{3,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
