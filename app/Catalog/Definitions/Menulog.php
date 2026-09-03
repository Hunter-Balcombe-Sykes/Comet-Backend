<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\Lifecycle;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Menulog — WLH-label ordering brand, new link-only surface. Host from
 * WebsiteLinkHarvester::ORDERING_HOSTS (verbatim); since Phase 6 split
 * ORDERING_PLATFORM per brand, WLH returns this surface key ('menulog.order')
 * rather than a generic 'online-ordering' bucket.
 */
class Menulog
{
    public static function brand(): Brand
    {
        return Brand::make('menulog', 'Menulog', 'https://www.menulog.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('menulog.order')
                ->displayName('Menulog')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                // RETIRED 2026-09-03: Menulog ceased Australian operations on
                // 2025-11-26 and menulog.com.au was its only market. A retired
                // surface routes to Verdict::Note (PlacementPolicy.php:83), so
                // an existing link survives as a plain link and is never again
                // offered as an ordering CONNECTION — which would have
                // attached a live order CTA to a dead brand.
                ->lifecycle(Lifecycle::Retired)
                // Connectable since convergence Phase 6 — see UberEats for the
                // rationale. Menulog has no menu scraper of its own in
                // config('partna.menu.platforms'), so this is a link-only
                // ordering brand today; the promotion is about giving the link
                // a real home, not about declaring scrape behaviour.
                ->detect(
                    Detector::url('menulog.com.au')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
