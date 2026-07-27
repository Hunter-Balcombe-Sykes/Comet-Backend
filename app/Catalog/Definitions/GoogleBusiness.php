<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Google Business. Connects via a Places API search
 * (ConnectGoogleBusinessRequest, PRSP:516-517) — never a URL paste, so no
 * detectors and no connect capability. The 2-day refreshEvery is the
 * dispatcher cadence; GoogleBusinessFetch additionally self-gates on a 40h
 * detailsFetchedAt freshness check (GoogleBusinessFetch.php:38-46) so a
 * manual refresh can't hammer Places inside that window. Single row per
 * user (routes SingleSelection, PRSP:621) — no ->multiAccount() call.
 */
class GoogleBusiness
{
    public static function brand(): Brand
    {
        return Brand::make('google_business', 'Google Business', 'https://business.google.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('google_business.listing')
                ->displayName('Google Business')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::PlaceId)
                ->refreshEvery(172800)
                ->fetch('fetch.google_business.places.v1')
                ->notConnectable()
                ->note('connects via the bespoke Places-search flow, not a catalog mechanism — §16 keeps places-search as an explicit add mode; GoogleBusinessFetch also self-gates on a 40h detailsFetchedAt freshness check')
                ->build(),
        ];
    }
}
