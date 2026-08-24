<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Skool — bespoke connect (SkoolController, via DefersBespokeConnect); the
 * registry itself documents "no ConnectStrategy at all" (PRSP:170-174), so
 * there is no catalog 'connect' capability to name. No detector either
 * (ground truth: "Detect: none"). notConnectable() reflects that the CATALOG
 * has no way to drive a connection here at P1 — the bespoke controller still
 * works, entirely outside catalog awareness. SkoolFetch IS real but is
 * consumed only by the deferred connect-fetch job, never cron-refreshed —
 * hence refreshEvery(0). The fetch capability went with the 2026-08-16
 * demotion — there is nothing left to scrape.
 */
class Skool
{
    public static function brand(): Brand
    {
        return Brand::make('skool', 'Skool', 'https://www.skool.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('skool.community')
                ->legacyPlatform('skool')
                ->displayName('Skool')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Community)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(0)
                ->connect('connect.skool.url.v1')
                ->note('link-only since 2026-08-16 (Phase 1.2): UrlConnect + SkoolNormalizer, no fetch, no refresh — the bespoke SkoolController and its scraper were deleted with the demotion')
                ->canonicalUrl('https://www.skool.com/{handle}')
                ->notConnectable()
                ->build(),
        ];
    }
}
