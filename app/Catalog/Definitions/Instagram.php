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
 * Instagram. Bespoke connect (InstagramController, Apify-backed) — no
 * connect capability. No detector exists in the legacy registry at all
 * (PRSP:336 connects via bespoke flow, not smart-detect); this is a NEW
 * detector added for the catalog. refreshEvery(0): refresh is a paid Apify
 * run, deliberately never cron-scheduled (PRSP:336 comment).
 */
class Instagram
{
    public static function brand(): Brand
    {
        return Brand::make('instagram', 'Instagram', 'https://instagram.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('instagram.profile')
                ->legacyPlatform('instagram')
                ->displayName('Instagram')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://instagram.com/{handle}')
                ->multiAccount(5)
                ->detect(
                    Detector::url('instagram.com')
                        ->path('#^/(?!(?:p|reel|reels|stories|explore|accounts|developer|about|legal|directory)(?:/|$))(?<handle>[A-Za-z0-9._]{1,30})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->note('bespoke connect + bespoke Apify fetch (P1) — no manifest FetchStrategy exists yet; the fetch.instagram.apify.v1 capability name arrives with the P3 connector')
                ->build(),
        ];
    }
}
