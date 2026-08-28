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
 * Linkfire — music smart links, link-only (2026-08-28). A lnk.to page is a
 * release landing page the ARTIST published and put in their own bio, which
 * is why this is ProfileLink and not the MarketplaceListing the ticketers
 * get: the link is theirs, not a third party's listing of them.
 *
 * NOT a ShortLinkExpander host, deliberately. lnk.to looks like a shortener
 * and is not one — it is a page with many destinations (Spotify, Apple,
 * Bandcamp…), so following it would pick one service arbitrarily and throw
 * the rest away. Same reasoning that keeps linktr.ee out of the expander.
 */
class Linkfire
{
    public static function brand(): Brand
    {
        return Brand::make('linkfire', 'Linkfire', 'https://www.linkfire.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('linkfire.release')
                ->displayName('Linkfire')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                // Detect-only: links arrive from scanned bios and site scans,
                // and the legacy harvester carries no classify() entry, so a
                // manual connect card would 422 its own URL (BrandCoverageTest).
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('lnk.to')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('linkfire.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
