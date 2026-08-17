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
 * TheFork — WLH-label reservations brand, new link-only surface. TLD set from
 * WebsiteLinkHarvester::RESERVATION_HOSTS (verbatim); WLH collapses it to the
 * generic 'reservations' pseudo-platform today.
 */
class Thefork
{
    /** @var list<string> */
    private const TLDS = ['com', 'com.au', 'com.br', 'com.ar', 'co.uk', 'fr', 'es', 'it', 'pt', 'nl', 'be', 'ch', 'at', 'de', 'dk', 'se', 'cl'];

    public static function brand(): Brand
    {
        return Brand::make('thefork', 'TheFork', 'https://www.thefork.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('thefork.reserve')
                ->displayName('TheFork')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("thefork.{$tld}")->strength(EvidenceStrength::ProfileLink),
                        self::TLDS,
                    ),
                )
                ->build(),
        ];
    }
}
