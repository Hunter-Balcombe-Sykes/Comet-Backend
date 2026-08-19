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
 * Circle. New link-only surface. Config-registered
 * (config('partna.social_platforms').circle, config/partna.php:648-658),
 * subdomain mode ({handle}.circle.so) — absent from WebsiteLinkHarvester
 * entirely. Subdomain capture translated verbatim from config's
 * handle_pattern. Routes Content per this file's special-case rule
 * (.community -> Content), shelved under Community.
 */
class Circle
{
    public static function brand(): Brand
    {
        return Brand::make('circle', 'Circle', 'https://circle.so');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('circle.community')
                ->displayName('Circle')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(5)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Community)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://{handle}.circle.so')
                ->detect(
                    Detector::url('circle.so')
                        ->subdomain('#^(?<handle>[a-zA-Z0-9-]{3,63})$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
