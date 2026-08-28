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
 * Libro.fm — audiobooks sold THROUGH an independent bookshop, link-only
 * (2026-08-28). Found on Readings' site scan as libro.fm/readings: the
 * bookshop's own audiobook storefront, which is why this is a Shop surface
 * and ProfileLink rather than a marketplace listing. One domain serves every
 * region.
 */
class LibroFm
{
    public static function brand(): Brand
    {
        return Brand::make('libro_fm', 'Libro.fm', 'https://libro.fm');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('libro_fm.store')
                ->displayName('Libro.fm')
                ->multiAccount(10)
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('libro.fm')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
