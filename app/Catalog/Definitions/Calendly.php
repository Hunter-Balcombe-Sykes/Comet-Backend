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
 * Calendly. New link-only surface. Config-registered
 * (config('partna.social_platforms').calendly, config/partna.php:586-596)
 * with a real path grammar — also a WebsiteLinkHarvester::BOOKING_HOSTS
 * label (WebsiteLinkHarvester.php:113) that today collapses into the
 * generic 'booking' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. Path capture translated verbatim from config's
 * url_path_extractor.
 */
class Calendly
{
    public static function brand(): Brand
    {
        return Brand::make('calendly', 'Calendly', 'https://calendly.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('calendly.book')
                ->displayName('Calendly')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://calendly.com/{handle}')
                ->detect(
                    Detector::url('calendly.com')
                        ->path('#^/(?<handle>[a-zA-Z0-9-]{2,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
