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
 * Tripadvisor — detect-only listing links (wave 2, 2026-08-28). The
 * -g{geo}-d{listing} ids are numeric; only Review pages are captured, and
 * category/forum pages never match. Path-filtered detectors can't back a
 * sane manual connect card (the WixBookings rule), so detect-only. Verified
 * example: tripadvisor.com/Restaurant_Review-g255060-d27541618-….html.
 */
class Tripadvisor
{
    public static function brand(): Brand
    {
        return Brand::make('tripadvisor', 'Tripadvisor', 'https://www.tripadvisor.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tripadvisor.listing')
                ->displayName('Tripadvisor')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('tripadvisor.com')
                        ->path('#^/(?:Restaurant|Hotel|Attraction)_Review-g\d+-d(?<id>\d+)-#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('tripadvisor.com.au')
                        ->path('#^/(?:Restaurant|Hotel|Attraction)_Review-g\d+-d(?<id>\d+)-#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
