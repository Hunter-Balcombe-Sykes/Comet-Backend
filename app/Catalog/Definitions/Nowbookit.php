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
 * NowBookit — keyless reservation widget. NowBookitService::parseIds() reads
 * accountid/venueid from the query string, accepting both the lowercase and
 * camelCase spelling of each param case-insensitively; the pair together is
 * the identity (Composite), so no single ->captures() name applies. No fetch
 * strategy exists (a stored URL + parsed ids only) — link-only, refreshEvery
 * 0.
 */
class Nowbookit
{
    public static function brand(): Brand
    {
        return Brand::make('nowbookit', 'NowBookit', 'https://www.nowbookit.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('nowbookit.reserve')
                ->legacyPlatform('nowbookit')
                ->displayName('NowBookit')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Composite)
                ->refreshEvery(0)
                ->note('accountId/venueId spellings both accepted case-insensitively per NowBookitService::parseIds()')
                ->connect('connect.nowbookit.url.v1')
                ->detect(
                    Detector::url('nowbookit.com')
                        ->query('accountid', 'venueid')
                        ->from(IdentifierSource::Query)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
