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
 * Resident Advisor — events/ticketing, detect-only. Real host is ra.co, a
 * different domain entirely from the brand name (PRSP:457's HostMatch,
 * verbatim). MarketplaceListing: a ticket seller, not a profile.
 */
class ResidentAdvisor
{
    public static function brand(): Brand
    {
        return Brand::make('resident_advisor', 'Resident Advisor', 'https://ra.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('resident_advisor.tickets')
                ->legacyPlatform('resident-advisor')
                ->displayName('Resident Advisor')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    // The ARTIST page (2026-08-28). ra.co/dj/<slug> is the one RA
                    // URL that names a person rather than a listing, and it is the
                    // exact identifier ResidentAdvisorConnector fetches a tour with
                    // (SourceProvisioner::residentAdvisorSlug). Left at the bare
                    // host's MarketplaceListing strength it scored 28 — below every
                    // threshold — so a DJ whose bio links their RA page got a dead
                    // link card while a working connector sat unused. A captured
                    // profile path is ProfileLink evidence, which is what the
                    // connection lane needs to fire.
                    Detector::url('ra.co')
                        ->path('#^/dj/(?<handle>[a-zA-Z0-9_-]{2,60})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://ra.co/dj/raha — verified live 2026-09-03'),
                    // Everything else on the host stays a listing: /events/<id>,
                    // /clubs/<id>, /promoters/<id> are someone's night, not the
                    // account holder's identity.
                    Detector::url('ra.co')->strength(EvidenceStrength::MarketplaceListing),
                )
                ->build(),
        ];
    }
}
