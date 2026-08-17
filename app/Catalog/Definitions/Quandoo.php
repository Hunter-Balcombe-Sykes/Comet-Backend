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
 * Quandoo — reservations, detect-only. TLD set from PRSP:447's HostMatch,
 * verbatim (also listed in WLH's RESERVATION_HOSTS, which collapses it to the
 * generic 'reservations' bucket rather than this dedicated key).
 */
class Quandoo
{
    /** @var list<string> */
    private const TLDS = ['com', 'com.au', 'de', 'at', 'ch', 'it', 'co.uk', 'sg', 'hk', 'nl', 'fi'];

    public static function brand(): Brand
    {
        return Brand::make('quandoo', 'Quandoo', 'https://www.quandoo.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('quandoo.reserve')
                ->displayName('Quandoo')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("quandoo.{$tld}")->strength(EvidenceStrength::ProfileLink),
                        self::TLDS,
                    ),
                )
                ->build(),
        ];
    }
}
