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
 * OpenTable — keyless reservation widget. TLDS is OpenTableService's own
 * closed suffix set (deliberately closed to prevent host-spoofing per that
 * class's docblock). One path detector per TLD (rid from
 * /restaurant/profile/<rid>) plus one query detector on opentable.com only
 * (rid from ?rid=) — a per-TLD query detector would be redundant since
 * OpenTableService::isOpenTableUrl already gates it, and duplicating the
 * query check per TLD adds nothing. Slug-only /r/<slug> links carry no rid
 * server-side (WAF-blocked scrape) — reservedPaths + note documents the gap
 * the connect flow nudges around.
 */
class Opentable
{
    /** @var list<string> */
    private const TLDS = ['com', 'com.au', 'com.mx', 'co.uk', 'co.th', 'ca', 'de', 'jp', 'ie', 'sg', 'hk', 'ae', 'it', 'es', 'nl', 'at'];

    public static function brand(): Brand
    {
        return Brand::make('opentable', 'OpenTable', 'https://www.opentable.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('opentable.reserve')
                ->legacyPlatform('opentable')
                ->displayName('OpenTable')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::NumericId)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.opentable.com/restaurant/profile/{rid}')
                ->connect('connect.opentable.url.v1')
                ->reservedPaths('/r/')
                ->note('slug links carry no rid — connect flow nudges to profile link')
                ->detect(
                    // Both shapes exist on EVERY regional domain: the profile
                    // path, and the ?rid= booking/widget link. Scoping the
                    // query rule to .com alone would silently drop every
                    // regional booking URL — including the AU ones that are
                    // our primary market.
                    ...array_merge(
                        // The note carries the live-verified example that
                        // LinkValidity::exampleFromNote() reduces to a shape, so
                        // a refused paste can be told what a good OpenTable link
                        // looks like instead of just that this one is not.
                        //
                        // It is attached to the ONE regional domain the example
                        // was actually verified on rather than interpolated
                        // across all sixteen. A note is read as ground truth —
                        // it is what the real-URL sweep re-checks against — and
                        // minting fifteen URLs nobody has fetched would be
                        // planting evidence for a future pass to trip over. One
                        // note is enough: shapeFor() returns the first hint it
                        // finds on the surface.
                        array_map(
                            fn (string $tld) => Detector::url("opentable.{$tld}")
                                ->path('#^/restaurant/profile/(?<rid>\d+)#')
                                ->captures('rid')
                                ->from(IdentifierSource::Path)
                                ->strength(EvidenceStrength::DeepLinkWithSlug)
                                ->note($tld === 'com.au' ? 'e.g. https://www.opentable.com.au/restaurant/profile/1000972 — verified live 2026-09-03; the path shape is identical on every regional domain' : ''),
                            self::TLDS,
                        ),
                        array_map(
                            fn (string $tld) => Detector::url("opentable.{$tld}")
                                ->query('rid')
                                ->captures('rid')
                                ->from(IdentifierSource::Query)
                                ->strength(EvidenceStrength::DeepLinkWithSlug),
                            self::TLDS,
                        ),
                        // M-3 (matrix run 2, chinchinrestaurant.com.au live):
                        // "Reserve" buttons on restaurant websites link
                        // /booking/restref/availability?...&restRef=<rid> —
                        // same numeric id space as rid, different key. Without
                        // this the links fell through the reservations lane
                        // and CARDED as two identical "www.opentable.com.au"
                        // custom links next to the already-connected
                        // opentable.reserve.
                        array_map(
                            fn (string $tld) => Detector::url("opentable.{$tld}")
                                ->query('restRef')
                                ->captures('restRef')
                                ->from(IdentifierSource::Query)
                                ->strength(EvidenceStrength::DeepLinkWithSlug),
                            self::TLDS,
                        ),
                    ),
                )
                ->build(),
        ];
    }
}
