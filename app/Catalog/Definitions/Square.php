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
 * Square — two surfaces resolve the legacy square/square-ordering dual
 * identity. square.book keeps the bare-host claims (squareup.com +
 * square.site — booking wins the ambiguous host, unchanged). square.order
 * became a REAL connectable ordering surface 2026-08-26 (menu deep-links
 * plan, A1): it detects square.site URLs carrying the /s/order ordering
 * path — the one URL shape that is unambiguously ordering (live-verified:
 * ordering stores also serve at the BARE square.site root, which stays
 * square.book's by host and is disambiguated by the connect flow's
 * storefront-marker probe instead, same mechanism as custom domains —
 * order.fat-tuna.com — where NO host rule may exist, see #SEC-3).
 * Scoring mirrors UberEats.php's: base 40 + path 35 + DeepLinkWithSlug
 * delta 4 = 79 clears ordering's suggest bar (55, RoutingPolicy) with room
 * under auto (80). Connect itself is the DERIVED Brand connect
 * (DerivedDescriptorFactory) — same as uber_eats.order; square.book's
 * bespoke booking controller is untouched.
 */
class Square
{
    public static function brand(): Brand
    {
        return Brand::make('square', 'Square', 'https://squareup.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('square.book')
                ->legacyPlatform('square')
                ->displayName('Square')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->note('square.site is assigned to square.book only — square.order is undetectable by host alone at P1; see square.order\'s note')
                ->detect(
                    Detector::url('squareup.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('square.site')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
            SurfaceBuilder::for('square.order')
                ->legacyPlatform('square-ordering')
                ->displayName('Square Online')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                // Single-account by deliberate omission of multiAccount() —
                // same owner ruling as uber_eats.order (2026-08-16): a second
                // store becomes a links-pool item.
                ->note('square.site + /s/order path = ordering, unambiguous; bare square.site stays square.book (host default) and is reclassified by the connect probe\'s storefront markers, the same evidence path custom-domain stores use')
                ->detect(
                    Detector::url('square.site')
                        ->path('#^/s/order(?:/|$|\?)#')
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->build(),
            // Wave 2 (2026-08-28): square.link checkout links, detect-only —
            // opaque slugs, so the win is recognition (a payment link routes
            // as a shop link instead of spending a commerce probe on it).
            SurfaceBuilder::for('square.payment_link')
                ->displayName('Square')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('square.link')
                        ->path('#^/(?:u/)?[A-Za-z0-9]{6,32}/?$#')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
