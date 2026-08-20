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
    /**
     * Single source of truth for this brand's regional TLDs — consumed by
     * WebsiteLinkHarvester::classify() and ItemLinkRules, never re-listed.
     *
     * @var list<string>
     */
    public const TLDS = ['com', 'com.au', 'co.nz', 'com.ar'];

    public static function brand(): Brand
    {
        return Brand::make('ticketek', 'Ticketek', 'https://www.ticketek.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ticketek.tickets')
                ->legacyPlatform('ticketek')
                ->displayName('Ticketek')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(10)
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
