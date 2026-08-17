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
 * Treatwell — WLH-label booking brand, new link-only surface. TLD set from
 * WebsiteLinkHarvester::BOOKING_HOSTS (verbatim); since Phase 6 WLH returns
 * this surface key ('treatwell.book') rather than a generic 'booking' bucket.
 */
class Treatwell
{
    /** @var list<string> */
    private const TLDS = ['com', 'co.uk', 'de', 'fr', 'nl', 'es', 'it', 'be', 'at', 'ch', 'ie', 'pt', 'lt', 'lv', 'gr'];

    public static function brand(): Brand
    {
        return Brand::make('treatwell', 'Treatwell', 'https://www.treatwell.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('treatwell.book')
                ->displayName('Treatwell')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("treatwell.{$tld}")->strength(EvidenceStrength::ProfileLink),
                        self::TLDS,
                    ),
                )
                ->build(),
        ];
    }
}
