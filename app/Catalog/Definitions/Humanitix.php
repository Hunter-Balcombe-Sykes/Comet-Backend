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
 * Humanitix. Bespoke connect (EventsPlatformController, same
 * shape as Eventbrite) — no connect capability. Path capture translated
 * from config('partna.social_platforms').humanitix's url_path_extractor
 * (config/partna.php:675-685): /host/<org> — the same organiser-page
 * semantics as Eventbrite's /o/ pattern, so it shares the same
 * DeepLinkWithSlug strength. host_allowlist also lists events.humanitix.com
 * (config/partna.php:681), a subdomain already covered by the
 * humanitix.com registrable key — no second detector needed.
 */
class Humanitix
{
    public static function brand(): Brand
    {
        return Brand::make('humanitix', 'Humanitix', 'https://humanitix.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('humanitix.organiser')
                ->displayName('Humanitix')
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(21600)
                ->canonicalUrl('https://humanitix.com/host/{org}')
                ->fetch('fetch.humanitix.scrape.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('humanitix.com')
                        ->path('#^/host/(?<org>[a-zA-Z0-9-]{3,80})/?$#')
                        ->captures('org')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->note('bespoke connect flow (P1)')
                ->build(),
        ];
    }
}
