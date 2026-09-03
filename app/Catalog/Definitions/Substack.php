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
 * Substack — one of the 11 "new" link-only socials with zero connect wiring
 * (inventory D2#5). WLH's substack.com host pattern matches any subdomain
 * (publications commonly live at <name>.substack.com), but no
 * SubstackNormalizer/ConnectStrategy exists to translate a publication-name
 * grammar from — bare host detector only, no invented capture.
 */
class Substack
{
    public static function brand(): Brand
    {
        return Brand::make('substack', 'Substack', 'https://substack.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('substack.publication')
                ->legacyPlatform('substack')
                ->displayName('Substack')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Media)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://{handle}.substack.com')
                ->detect(
                    Detector::url('substack.com')
                        ->subdomain('#^(?<handle>[a-z0-9-]{2,63})$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://garbageday.substack.com — verified live 2026-09-03'),
                    Detector::url('substack.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
