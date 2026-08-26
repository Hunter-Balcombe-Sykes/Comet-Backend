<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Skool — link-only since the 2026-08-16 demotion. PD-retirement P2
 * (2026-08-27): the surface became CONNECTABLE — the P1-era
 * notConnectable() reflected a bespoke controller that the demotion
 * deleted, and the catalog now drives the connection through the derived
 * LinkOnly descriptor (LinkOnlyBindings: UrlConnect + SkoolNormalizer,
 * `url` field, the historical 422 copy). No detector (ground truth:
 * "Detect: none"); refreshEvery(0) — nothing left to scrape.
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
                ->build(),
        ];
    }
}
