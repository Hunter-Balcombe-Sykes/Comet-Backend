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
 * Postmates — delivery/ordering, link-only (2026-08-30). Found on
 * beaker-flask-wine-co's ordering fan-out as an `ordering_unroutable` host.
 *
 * Uber owns it, and it keeps its own brand and its own domain — hence its own
 * entry rather than an uber_eats alias: a Postmates link should say Postmates,
 * which is what the venue published.
 */
class Postmates
{
    public static function brand(): Brand
    {
        return Brand::make('postmates', 'Postmates', 'https://postmates.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('postmates.order')
                ->displayName('Postmates')
                ->multiAccount(10)
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('postmates.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
