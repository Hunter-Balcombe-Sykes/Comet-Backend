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
 * Noterro — WLH-label booking brand, new link-only surface. Host from
 * WebsiteLinkHarvester::BOOKING_HOSTS (verbatim); since Phase 6 WLH returns
 * this surface key ('noterro.book') rather than a generic 'booking' bucket.
 * Two real shapes confirmed live (batch T27b): a per-clinic tenant subdomain
 * (<clinic>.noterro.com — excludes Noterro's own 'app' portal subdomain,
 * which carries the second shape below) and, for clinics on the shared
 * app.noterro.com portal, /calendars/bookOnlineStepOne/<32-char hex id>.
 */
class Noterro
{
    public static function brand(): Brand
    {
        return Brand::make('noterro', 'Noterro', 'https://www.noterro.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('noterro.book')
                ->displayName('Noterro')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('noterro.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('noterro.com')
                        ->subdomain('#^(?!(?:www|app|api|admin|help|support|status|blog)$)(?<tenant>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://bodyrenewal.noterro.com/'),
                    Detector::url('noterro.com')
                        ->subdomain('#^app$#i')
                        ->path('#^/calendars/bookOnlineStepOne/(?<id>[a-f0-9]{32})#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://app.noterro.com/calendars/bookOnlineStepOne/4e36d0f16322400e5f80b35dd033baa5'),
                    // The same booking path is served on the BARE host, and
                    // that is the form people actually hold: Noterro's own
                    // booking links omit the subdomain and 302 to the tenant
                    // site (verified 2026-09-03 — noterro.com/calendars/
                    // bookOnlineStepOne/bd467b8d… → 200 at nourish.noterro.com).
                    // A subdomain-constrained detector can never match a bare
                    // host (LinkProjector:222 returns null on a null subdomain),
                    // so these were falling through to the host-only rule and
                    // connecting with no identifier.
                    Detector::url('noterro.com')
                        ->path('#^/calendars/bookOnlineStepOne/(?<id>[a-f0-9]{32})#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://noterro.com/calendars/bookOnlineStepOne/bd467b8df43f6629db182c54f305bbc2'),
                )
                ->build(),
        ];
    }
}
