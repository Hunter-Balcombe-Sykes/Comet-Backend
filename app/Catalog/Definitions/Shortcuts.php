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
 * Shortcuts — 27-set booking, detect-only. Two real hosts (PRSP:439's
 * HostMatch, verbatim): shortcuts.com.au and shortcuts.net.
 */
class Shortcuts
{
    public static function brand(): Brand
    {
        return Brand::make('shortcuts', 'Shortcuts', 'https://www.shortcuts.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('shortcuts.book')
                ->legacyPlatform('shortcuts')
                ->displayName('Shortcuts')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('shortcuts.com.au')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('shortcuts.net')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
