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
 * Bopple. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch. Two hosts verbatim from PRSP:463
 * (`~(^|\.)bopple\.(com|me|app)$~`).
 *
 * bopple.app also gets a slug sibling (verified live 2026-09-03 — fetched
 * https://bopple.app/comedy-store and confirmed the SPA's inline
 * `window.pageLoadData.venue.slug` matches the URL segment). Bopple's
 * grammar has NO path prefix — every venue is a single root-level segment
 * (`bopple.app/<slug>`), so the reject list is the confirmed set of
 * reserved app routes (login, search, cart, etc. — probed live, each one
 * resolves with no `venue` key in pageLoadData) that a bare `[a-z0-9-]+`
 * capture would otherwise swallow.
 */
class Bopple
{
    public static function brand(): Brand
    {
        return Brand::make('bopple', 'Bopple', 'https://bopple.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bopple.order')
                ->legacyPlatform('bopple')
                ->displayName('Bopple')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('bopple.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('bopple.me')->strength(EvidenceStrength::ProfileLink),
                    // bopple.app: where EVERY real customer ordering URL
                    // actually lives (bopple.com is the B2B marketing site,
                    // .me 301s to it). The harvester learned this host in a
                    // prior fix and its comment flagged this detector as the
                    // remaining gap — closed plan-03 batch 9, 2026-08-27,
                    // verified against two real restaurants' indexed links.
                    Detector::url('bopple.app')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('bopple.app')
                        ->path('#^/(?!(?:login|search|cart|account|help|about|terms|privacy|signup|venues|explore|discover|app|download|blog)/?$)(?<slug>[a-z0-9][a-z0-9-]*)/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://bopple.app/comedy-store'),
                )
                ->build(),
        ];
    }
}
