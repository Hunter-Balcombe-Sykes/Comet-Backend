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
 * ResDiary — keyless reservation widget. Three grammars from
 * ResDiaryService::parseMicrosite: a /widget/<type>/<name> path, a
 * /restaurant|r/<name> path, and a <name>.resdiary.com tenant subdomain
 * (excluding the generic www/booking/book/secure hosts).
 */
class Resdiary
{
    public static function brand(): Brand
    {
        return Brand::make('resdiary', 'ResDiary', 'https://www.resdiary.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('resdiary.reserve')
                ->legacyPlatform('resdiary')
                ->displayName('ResDiary')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(0)
                ->connect('connect.resdiary.url.v1')
                ->detect(
                    Detector::url('resdiary.com')
                        ->path('#^/widget/[^/]+/(?<name>[^/?]+)#')
                        ->captures('name')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://booking.resdiary.com/widget/Standard/CafeAndaluzCity/29211 — verified live 2026-09-03'),
                    Detector::url('resdiary.com')
                        ->path('#^/(?:restaurant|r)/(?<name>[^/?]+)#')
                        ->captures('name')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('resdiary.com')
                        ->subdomain('#^(?!www$|booking$|book$|secure$)(?<name>[a-z0-9-]+)$#')
                        ->captures('name')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
