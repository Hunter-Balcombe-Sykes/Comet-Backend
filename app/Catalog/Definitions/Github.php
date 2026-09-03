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
 * GitHub. Registered (PRSP:134, linkOnly) with zero connect wiring, though
 * WebsiteLinkHarvester DOES auto-harvest it
 * (WebsiteLinkHarvester.php:43) — there is simply no manual "paste a URL"
 * path for it today (inventory D2 #5). Host verbatim from that same line.
 * No capture: no normalizer grammar exists to translate faithfully.
 */
class Github
{
    public static function brand(): Brand
    {
        return Brand::make('github', 'GitHub', 'https://github.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('github.profile')
                ->legacyPlatform('github')
                ->displayName('GitHub')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://github.com/{handle}')
                ->detect(
                    Detector::url('github.com')
                        ->path('#^/(?<handle>[A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:features|pricing|login|join|about|explore|marketplace|sponsors|topics|trending|settings|orgs|enterprise|security|customer\-stories|readme|collections|events|apps|site|contact|new|notifications|issues|pulls|codespaces)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://github.com/gaearon — verified live 2026-09-03'),
                    Detector::url('github.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
