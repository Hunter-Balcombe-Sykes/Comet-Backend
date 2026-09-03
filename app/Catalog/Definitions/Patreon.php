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
 * Patreon — one of the 11 "new" link-only socials with zero connect wiring
 * (inventory D2#5). WLH does classify patreon.com for auto-harvest
 * (SOCIAL_HOSTS), but no PatreonNormalizer/ConnectStrategy exists anywhere in
 * the codebase — the detector is a bare host match (no path/handle grammar to
 * translate; inventing one would not be grounded in real code).
 */
class Patreon
{
    public static function brand(): Brand
    {
        return Brand::make('patreon', 'Patreon', 'https://www.patreon.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('patreon.page')
                ->legacyPlatform('patreon')
                ->displayName('Patreon')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.patreon.com/{handle}')
                ->detect(
                    Detector::url('patreon.com')
                        ->path('#^/(?:c/|cw/)?(?<handle>[A-Za-z0-9_-]{2,64})(?:/(?:posts|about|membership|shop|collections))?/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:login|signup|home|explore|about|pricing|policy|careers|press|blog|create|creators|product|features|apps|settings|join|checkout|oauth2|api|search|messages|notifications|discover|podcasts|video|community|store|help)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://www.patreon.com/PatreonforCreators — verified live 2026-09-03'),
                    Detector::url('patreon.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
