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
 * YouCanBook.me — appointment booking, link-only (2026-08-28).
 *
 * Found as an ORPHANED suffix override: youcanbook.me has been in
 * Hosts::suffixOverrides() since the tenant-host work, so the router has
 * always treated `<tenant>.youcanbook.me` as its own registrable key — but no
 * brand ever existed to match it, so every YouCanBook.me link fell through to
 * no-rule-matched. The override was written for a platform nobody modelled.
 *
 * Host-level, no capture: like Setmore and Acuity beside it, the tenant label
 * is the identity and the path is the vendor's own booking-flow grammar.
 * Detect-only — the legacy harvester carries no classify() entry for it, and a
 * connect card whose own URL 422s is worse than none (BrandCoverageTest).
 */
class YouCanBookMe
{
    public static function brand(): Brand
    {
        return Brand::make('youcanbookme', 'YouCanBook.me', 'https://youcanbook.me');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('youcanbookme.book')
                ->displayName('YouCanBook.me')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('youcanbook.me')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
