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
 * GitLab. Registered (PRSP:135, linkOnly) with zero connect wiring and
 * absent from WebsiteLinkHarvester entirely (inventory D2 #5). Host is this
 * file's own addition (well-known real domain).
 */
class Gitlab
{
    public static function brand(): Brand
    {
        return Brand::make('gitlab', 'GitLab', 'https://gitlab.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('gitlab.profile')
                ->legacyPlatform('gitlab')
                ->displayName('GitLab')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://gitlab.com/{handle}')
                ->detect(
                    Detector::url('gitlab.com')
                        ->path('#^/(?<handle>[A-Za-z0-9_.][A-Za-z0-9_.-]{1,254})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:explore|users|help|dashboard|projects|groups|pricing|about|features|solutions|sign_in|login|admin|api|search)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://gitlab.com/DylanGriffith — verified live 2026-09-03'),
                    Detector::url('gitlab.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
