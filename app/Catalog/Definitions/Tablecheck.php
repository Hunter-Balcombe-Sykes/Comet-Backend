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
 * TableCheck — reservations, detect-only (logo card, no connect anywhere).
 *
 * Shop pages live at /shops/<slug>(/reserve) — confirmed live, both with and
 * without a two-letter locale prefix (e.g.
 * https://www.tablecheck.com/en/shops/restaure/reserve and
 * .../shops/restaure both render RESTAURE's page), distinct from the
 * account/marketing tree (/en/join, /en/account/..., /en/users/sign_in)
 * which never has a /shops/ segment.
 */
class Tablecheck
{
    public static function brand(): Brand
    {
        return Brand::make('tablecheck', 'TableCheck', 'https://www.tablecheck.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tablecheck.reserve')
                ->legacyPlatform('tablecheck')
                ->displayName('TableCheck')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('tablecheck.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('tablecheck.com')
                        ->path('#^/(?:[a-z]{2}/)?shops/(?<shop>[^/?]+)#i')
                        ->captures('shop')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.tablecheck.com/en/shops/restaure/reserve'),
                )
                ->build(),
        ];
    }
}
