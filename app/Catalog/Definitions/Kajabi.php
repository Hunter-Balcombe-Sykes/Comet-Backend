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
 * Kajabi. New link-only surface. Config-registered
 * (config('partna.social_platforms').kajabi, config/partna.php:637-647),
 * subdomain mode ({handle}.mykajabi.com) — absent from WebsiteLinkHarvester
 * entirely. Subdomain capture translated verbatim from config's
 * handle_pattern. Routes Content per this file's special-case rule
 * (.courses -> Content), shelved under Education.
 */
class Kajabi
{
    public static function brand(): Brand
    {
        return Brand::make('kajabi', 'Kajabi', 'https://www.kajabi.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('kajabi.courses')
                ->displayName('Kajabi')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Education)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://{handle}.mykajabi.com')
                ->detect(
                    Detector::url('mykajabi.com')
                        ->subdomain('#^(?<handle>[a-zA-Z0-9-]{3,63})$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
