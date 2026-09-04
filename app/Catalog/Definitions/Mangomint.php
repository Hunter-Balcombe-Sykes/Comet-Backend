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
 * Mangomint — 27-set booking, detect-only (logo card, no connect anywhere).
 * Direct booking links are booking.mangomint.com/<numeric id> (confirmed
 * live, batch T27b) — a fixed subdomain, not per-tenant.
 */
class Mangomint
{
    public static function brand(): Brand
    {
        return Brand::make('mangomint', 'Mangomint', 'https://mangomint.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('mangomint.book')
                ->legacyPlatform('mangomint')
                ->displayName('Mangomint')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('mangomint.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('mangomint.com')
                        ->subdomain('#^booking$#i')
                        ->path('#^/(?<id>\d+)/?$#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://booking.mangomint.com/604020'),
                )
                ->build(),
        ];
    }
}
