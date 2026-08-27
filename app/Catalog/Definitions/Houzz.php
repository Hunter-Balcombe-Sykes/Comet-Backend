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
 * Houzz — detect-only professional profile (wave 2, 2026-08-28). Profile
 * URLs are /professionals/{category}/{slug}-pf~{id}; a lone category segment
 * is a directory page and never matches. Path-filtered only, so detect-only
 * (the WixBookings rule). Verified example:
 * houzz.com.au/professionals/interior-designers-and-decorators/dani-louis-design-pfvwau-pf~503457392.
 */
class Houzz
{
    public static function brand(): Brand
    {
        return Brand::make('houzz', 'Houzz', 'https://www.houzz.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('houzz.pro')
                ->displayName('Houzz')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('houzz.com')
                        ->path('#^/professionals/[a-z0-9-]+/(?<slug>[a-z0-9~_.-]+)/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('houzz.com.au')
                        ->path('#^/professionals/[a-z0-9-]+/(?<slug>[a-z0-9~_.-]+)/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
