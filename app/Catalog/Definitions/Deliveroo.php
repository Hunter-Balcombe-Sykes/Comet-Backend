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
 * Deliveroo. New link-only surface — today a
 * WebsiteLinkHarvester::ORDERING_HOSTS label
 * (WebsiteLinkHarvester.php:92) that collapses into the generic
 * 'online-ordering' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. 12 regional TLDs verbatim from that regex. No config
 * entry — host-only detectors, no capture.
 */
class Deliveroo
{
    /** Regional TLDs verbatim from WebsiteLinkHarvester.php:92's ORDERING_HOSTS regex. */
    private const TLDS = [
        'com', 'co.uk', 'fr', 'ie', 'it', 'be', 'nl', 'sg', 'hk', 'ae', 'com.kw', 'qa',
    ];

    public static function brand(): Brand
    {
        return Brand::make('deliveroo', 'Deliveroo', 'https://deliveroo.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('deliveroo.order')
                ->displayName('Deliveroo')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(...array_map(
                    static fn (string $tld) => Detector::url("deliveroo.{$tld}")
                        ->strength(EvidenceStrength::ProfileLink),
                    self::TLDS,
                ))
                ->build(),
        ];
    }
}
