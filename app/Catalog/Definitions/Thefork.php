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
 * TheFork — WLH-label reservations brand, new link-only surface. TLD set from
 * WebsiteLinkHarvester::RESERVATION_HOSTS (verbatim); since Phase 6 WLH returns
 * this surface key ('thefork.reserve') rather than a generic bucket.
 *
 * Restaurant pages live at /restaurant/<slug>-r<id> (singular) — confirmed
 * live, e.g. https://www.thefork.com/restaurant/confraria-sushi-cascais-r718637
 * renders Confraria Sushi Cascais's real page. City browse pages use the
 * PLURAL /restaurants/<city>-c<id> (e.g. /restaurants/new-york-c665788),
 * which the singular anchor never matches.
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
                    ...array_merge(
                        array_map(
                            fn (string $tld) => Detector::url("thefork.{$tld}")->strength(EvidenceStrength::ProfileLink),
                            self::TLDS,
                        ),
                        array_map(
                            fn (string $tld) => Detector::url("thefork.{$tld}")
                                ->path('#^/restaurant/(?<slug>[^/?]+)#i')
                                ->captures('slug')
                                ->from(IdentifierSource::Path)
                                ->strength(EvidenceStrength::DeepLinkWithSlug)
                                ->note('e.g. https://www.thefork.com/restaurant/confraria-sushi-cascais-r718637'),
                            self::TLDS,
                        ),
                    ),
                )
                ->build(),
        ];
    }
}
