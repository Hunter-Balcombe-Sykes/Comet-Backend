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
 * Behance. Registered (PRSP:136, linkOnly) but with zero connect wiring —
 * no ->connect()/->connectInput()/->routes() anywhere in the legacy
 * registry (inventory D2 #5). Host verbatim from
 * WebsiteLinkHarvester::SOCIAL_HOSTS (WebsiteLinkHarvester.php:44) — the
 * harvester's only awareness of this brand. No capture: no normalizer
 * grammar exists to translate faithfully.
 */
class Behance
{
    public static function brand(): Brand
    {
        return Brand::make('behance', 'Behance', 'https://www.behance.net');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('behance.profile')
                ->legacyPlatform('behance')
                ->displayName('Behance')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.behance.net/{handle}')
                ->detect(
                    Detector::url('behance.net')
                        ->path('#^/(?<handle>[A-Za-z0-9_-]{2,64})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:galleries|gallery|search|joblist|jobs|assets|livestreams|live|hire|login|signup|onboarding|collection|collections|misc|about|careers|contact|pro|projects|for_you|discover|settings)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://www.behance.net/joshuadavis — verified live 2026-09-03'),
                    Detector::url('behance.net')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
