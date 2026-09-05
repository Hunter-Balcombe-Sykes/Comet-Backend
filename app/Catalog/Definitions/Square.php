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
                    // 2026-09-05: the tenant subdomain IS the account on
                    // square.site (tough-luck-barbershop.square.site is one
                    // studio's booking page), so the rule constrains it —
                    // host-only, PlacementPolicy's invalid_identifier rule read
                    // it as "matched the brand, not an account page" and Noted
                    // the studio's own page to a card once booking stopped
                    // taking the legacy seedBooking() lane. The path guard keeps
                    // /s/order out of this rule entirely, so square.order's
                    // ordering link is never contested by it (LinkProjector
                    // relies on the bare-host fallback not constraining).
                    Detector::url('square.site')
                        ->subdomain('#^(?<tenant>[a-z0-9][a-z0-9-]{1,62})$#i')
                        ->path('#^/(?!s/order(?:/|$))#')
                        ->strength(EvidenceStrength::ProfileLink),
                    // 2026-09-02: the Square Appointments deep link Square's own
                    // "Book now" buttons and share sheets hand out. On the
                    // host-only rule above it scored 32 (40 − 8 deep-path), under
                    // booking's suggest bar of 55, so jessejensz's team-member
                    // link was carded as a custom link while the studio's bare
                    // square.site root took the Square slot. subdomain 20 + path
                    // 35 + DeepLinkWithSlug 4 on the 40 base = 99 — auto even
                    // after the 10-point indirect penalty. No captures(): the
                    // surface is IdentifierKind::Url and the canonical URL (which
                    // keeps team_member_id) is the identity.
                    Detector::url('squareup.com')
                        ->subdomain('#^book$#')
                        ->path('#^/appointments/(?<merchant>[a-z0-9]{8,32})(?:/location/(?<location>[A-Z0-9]{8,32}))?(?:/(?:services|staff)(?:/[A-Za-z0-9]+)?)?/?$#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                    // The booking_flow_url shape the buyer widget JSON carries.
                    Detector::url('squareup.com')
                        ->subdomain('#^app$#')
                        ->path('#^/appointments/book/(?<merchant>[a-z0-9]{8,32})/(?<location>[A-Z0-9]{8,32})(?:/start)?/?$#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
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
