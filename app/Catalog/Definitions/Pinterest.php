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
 * Pinterest — link-only social (wave 2, 2026-08-28). Regional hosts are
 * SUBDOMAINS (au.pinterest.com), not ccTLDs, so the registrable-key detector
 * covers them all. Verified example: au.pinterest.com/insidesmb/.
 */
class Pinterest
{
    public static function brand(): Brand
    {
        return Brand::make('pinterest', 'Pinterest', 'https://www.pinterest.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('pinterest.profile')
                // The 2026-07-28 retirement's legacy slug, revived with the
                // brand — the backfill-migration CASE pair check needs the
                // pair present in the historical map, and live beats retired.
                ->legacyPlatform('pinterest')
                ->displayName('Pinterest')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Connectable, unlike the rest of the wave-2 batch: the legacy
                // harvester has carried Pinterest in LINK_ONLY_HOSTS since
                // 2026-08-11, so its connect card classifies its own URL, and
                // the frozen legacy slug must resolve to a descriptor
                // (RegistryCoverageTest's vaporized-platform rule).
                ->refreshEvery(0)
                ->canonicalUrl('https://www.pinterest.com/{handle}/')
                ->detect(
                    Detector::url('pinterest.com')
                        ->path('#^/(?<handle>[A-Za-z0-9_]{3,30})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:pin|ideas|search|settings|login|business|today|shopping|about|careers|newsroom|policy|help|resource|watch)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://www.pinterest.com/marthastewart/ — verified live 2026-09-03'),
                )
                ->build(),
        ];
    }
}
