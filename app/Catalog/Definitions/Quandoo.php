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
 * Quandoo — reservations, detect-only. TLD set from PRSP:447's HostMatch,
 * verbatim (also listed in WLH's RESERVATION_HOSTS, which since Phase 6 agrees
 * with this key and returns 'quandoo' rather than a generic bucket).
 *
 * Restaurant pages live at /place/<slug>-<id> — confirmed live (bare numeric
 * /place/{id} 301s to the slugged URL for a real id, 404s for a fake one:
 * e.g. https://www.quandoo.com.au/place/ricks-place-92706 vs
 * .../place/999999999) — and several ccTLDs (.ch confirmed) also serve a
 * two-letter-locale-prefixed form, e.g.
 * https://www.quandoo.ch/en/place/yens-restaurant-11833. One regex admits
 * both the bare and locale-prefixed forms and captures the trailing numeric
 * id, which is the only part that's actually load-bearing.
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
                ->legacyPlatform('quandoo')
                ->displayName('Quandoo')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_merge(
                        array_map(
                            fn (string $tld) => Detector::url("quandoo.{$tld}")->strength(EvidenceStrength::ProfileLink),
                            self::TLDS,
                        ),
                        array_map(
                            fn (string $tld) => Detector::url("quandoo.{$tld}")
                                ->path('#^/(?:[a-z]{2}/)?place/(?:[a-z0-9]+-)*(?<place>\d+)(?:/|$)#i')
                                ->captures('place')
                                ->from(IdentifierSource::Path)
                                ->strength(EvidenceStrength::DeepLinkWithSlug)
                                ->note('e.g. https://www.quandoo.com.au/place/ricks-place-92706 and https://www.quandoo.ch/en/place/yens-restaurant-11833'),
                            self::TLDS,
                        ),
                    ),
                )
                ->build(),
        ];
    }
}
