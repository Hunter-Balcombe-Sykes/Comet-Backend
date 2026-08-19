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
 * Apple Music. Bespoke connect (AppleController::connectFor() via
 * DefersBespokeConnect, searches iTunes by artist name — never a registered
 * ConnectStrategy) — no connect capability, P1 leaves that flow as-is. The
 * music.apple.com detector has no identifier capture: the path shape
 * (locale-prefixed /artist/…) carries no stable slug worth extracting until
 * a real connect grammar exists for it.
 */
class AppleMusic
{
    public static function brand(): Brand
    {
        return Brand::make('apple_music', 'Apple Music', 'https://music.apple.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('apple_music.artist')
                ->displayName('Apple Music')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(43200)
                ->fetch('fetch.apple_music.itunes.v1')
                ->multiAccount(10)
                ->detect(
                    // Keyed on the REGISTRABLE domain with the product
                    // subdomain as its own rule: the router looks detectors up
                    // by eTLD+1, so a key of `music.apple.com` is never
                    // consulted and the surface would be undetectable.
                    // Captures the artist id (F9, 2026-08-20): with no
                    // capture, a reconciler placement keyed the row on the
                    // FULL URL and the Platforms page showed that URL as the
                    // account name.
                    Detector::url('apple.com')
                        ->subdomain('#^music$#')
                        ->path('#^/(?:[a-z]{2}/)?artist(?:/[^/]+)?/(?<id>\\d+)#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->note('bespoke connect flow (P1)')
                ->build(),
        ];
    }
}
