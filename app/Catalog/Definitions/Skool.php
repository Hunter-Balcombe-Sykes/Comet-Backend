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
 * hence refreshEvery(0) despite carrying a fetch capability.
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
                ->displayName('Skool')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Community)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(0)
                ->note('bespoke connect via SkoolController — no registered ConnectStrategy (PRSP:170-174); fetch is consumed only by the deferred connect-fetch job, never cron-refreshed')
                ->canonicalUrl('https://www.skool.com/{handle}')
                ->fetch('fetch.skool.scrape.v1')
                ->notConnectable()
                ->build(),
        ];
    }
}
