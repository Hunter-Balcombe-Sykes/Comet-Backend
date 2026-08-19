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
 * Luma. Link-only surface.
 *
 * Events-parity (2026-08-19): the organiser detector is /user/<handle> ONLY.
 * The original config-translated grammar treated a bare lu.ma/<slug> as the
 * calendar page, but bare slugs are Luma's EVENT URLs too (the two shapes
 * are statically indistinguishable), so every pasted event link scored 75
 * and PLACED a calendar connection — the same bug Eventbrite's
 * reservedPaths('/e/') exists to prevent, resolved the same direction
 * WebsiteLinkHarvester::classify() always ruled: bare slug = event (it
 * falls through to the events-pool item lane), /user/<handle> = organiser.
 * A calendar-slug page pasted whole now seeds its next event or cards —
 * an acceptable trade against connecting an account off every ticket link.
 */
class Luma
{
    public static function brand(): Brand
    {
        return Brand::make('luma', 'Luma', 'https://lu.ma');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('luma.calendar')
                ->displayName('Luma')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(5)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://lu.ma/user/{handle}')
                ->detect(
                    Detector::url('lu.ma')
                        ->path('#^/user/(?<handle>[a-zA-Z0-9-]{2,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
