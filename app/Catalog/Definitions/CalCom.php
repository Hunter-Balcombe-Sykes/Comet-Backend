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
 * Cal.com — link-only booking page (wave 2, 2026-08-28). Flat namespace
 * with team pages joined by + (cal.com/peer+bailey — verified via its 307 to
 * i.cal.com); the reject list carries the app's own roots.
 */
class CalCom
{
    public static function brand(): Brand
    {
        return Brand::make('cal_com', 'Cal.com', 'https://cal.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('cal_com.book')
                ->displayName('Cal.com')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://cal.com/{handle}')
                ->detect(
                    Detector::url('cal.com')
                        ->path('#^/(?<handle>[a-z0-9_-]{2,64}(?:\+[a-z0-9_-]+)*)(?:/[a-z0-9-]{1,64})?/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:login|signup|event-types|bookings|workflows|blog|docs|embed|enterprise|pricing|apps|auth|api|availability|teams|settings|video|routing-forms|insights|help|privacy|terms|security|download|solutions|platform|ai|enterprise-sso)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://cal.com/bailey — verified live 2026-09-03'),
                )
                ->build(),
        ];
    }
}
