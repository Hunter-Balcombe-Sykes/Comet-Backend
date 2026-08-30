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
 * Hey You — Australian order-ahead, link-only (2026-08-30).
 *
 * Found the way this class of gap is meant to be found: a
 * `platforms.google_business.ordering_unroutable` warning on
 * mrsister-coffee-newcastle, naming a host the router could not place. That
 * log line is the ordering lane telling us a real venue had a real ordering
 * link and we had nothing to route it to.
 *
 * Confirmed a genuine platform before it got an entry: heyyou.com.au ships an
 * apple-itunes-app tag for bundle com.beattheq.beattheqmobile (Beat the Queue,
 * the brand Hey You grew out of) and serves per-city venue data. Its homepage
 * is a JS shell with an empty <title>, which is why identity had to come from
 * the app tag rather than the page text.
 */
class HeyYou
{
    public static function brand(): Brand
    {
        return Brand::make('hey_you', 'Hey You', 'https://heyyou.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('hey_you.order')
                ->displayName('Hey You')
                ->multiAccount(10)
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('heyyou.com.au')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
