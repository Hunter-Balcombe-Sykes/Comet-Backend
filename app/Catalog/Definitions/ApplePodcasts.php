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
 * Apple Podcasts. Bespoke connect (same AppleController::connectFor()
 * pattern as Apple Music, via DefersBespokeConnect — never a registered
 * ConnectStrategy) — no connect capability. The podcasts.apple.com detector
 * has no identifier capture, same reasoning as Apple Music's. Registry key
 * is 'apple-podcast' (hyphen, singular) but this surface uses the plural
 * 'apple_podcasts' per LegacyPlatformMap — a known naming-variant mismatch
 * with the separate config('partna.social_platforms').apple_podcasts entry
 * (inventory D2 #1), not something this surface needs to reconcile.
 */
class ApplePodcasts
{
    public static function brand(): Brand
    {
        return Brand::make('apple_podcasts', 'Apple Podcasts', 'https://podcasts.apple.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('apple_podcasts.show')
                ->displayName('Apple Podcasts')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Podcast)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(43200)
                ->fetch('fetch.apple_podcasts.itunes.v1')
                ->multiAccount(5)
                ->detect(
                    // Keyed on the REGISTRABLE domain + product subdomain —
                    // see the note in AppleMusic: a full-host key is never
                    // looked up by the router.
                    Detector::url('apple.com')
                        ->subdomain('#^podcasts$#')
                        ->path('#^/(?:[a-z]{2}/)?podcast(/|$)#')
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->note('bespoke connect flow (P1)')
                ->build(),
        ];
    }
}
