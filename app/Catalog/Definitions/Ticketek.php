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
 * Ticketek — events/ticketing, detect-only, enumerated TLD loop (PRSP:451,
 * verbatim). MarketplaceListing strength: ticket sellers, not profiles.
 */
class Ticketek
{
    /** @var list<string> */
    private const TLDS = ['com', 'com.au', 'co.nz', 'com.ar'];

    public static function brand(): Brand
    {
        return Brand::make('ticketek', 'Ticketek', 'https://www.ticketek.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ticketek.tickets')
                ->displayName('Ticketek')
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("ticketek.{$tld}")->strength(EvidenceStrength::MarketplaceListing),
                        self::TLDS,
                    ),
                )
                ->build(),
        ];
    }
}
