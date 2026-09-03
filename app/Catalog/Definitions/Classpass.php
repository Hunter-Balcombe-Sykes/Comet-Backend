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
 * ClassPass — link-only studio listing (wave 2, 2026-08-28). One global
 * host; the city is folded into the slug. Verified example:
 * classpass.com/studios/passion-studio-melbourne (bot-403 but form-true).
 */
class Classpass
{
    public static function brand(): Brand
    {
        return Brand::make('classpass', 'ClassPass', 'https://classpass.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('classpass.book')
                ->displayName('ClassPass')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://classpass.com/studios/{handle}')
                ->detect(
                    Detector::url('classpass.com')
                        ->path('#^/studios/(?<handle>[a-z0-9-]+)/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://classpass.com/studios/real-pilates-tribeca--new-york — verified live 2026-09-03'),
                )
                ->build(),
        ];
    }
}
