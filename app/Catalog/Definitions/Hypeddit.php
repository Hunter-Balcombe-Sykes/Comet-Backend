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
 * Hypeddit — music smart links and download gates, link-only (2026-08-29,
 * cold-build round 4). Found on kjah_dnb as hypeddit.com/<artist>/<track>.
 *
 * Joins Linkfire, Feature.fm and The Orchard: ProfileLink, because the page
 * is the ARTIST's own release page rather than someone's listing of them —
 * and, like them, deliberately NOT a ShortLinkExpander host. It looks like a
 * shortener and is not one: the page's whole purpose is to offer many
 * destinations at once, so following it would pick one arbitrarily and throw
 * the rest away.
 */
class Hypeddit
{
    public static function brand(): Brand
    {
        return Brand::make('hypeddit', 'Hypeddit', 'https://hypeddit.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('hypeddit.release')
                ->displayName('Hypeddit')
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('hypeddit.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
