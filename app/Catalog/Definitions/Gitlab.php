<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
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
                ->displayName('GitLab')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('gitlab.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
