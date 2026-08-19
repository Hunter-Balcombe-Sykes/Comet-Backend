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
 * Mixcloud — keyless embed with zero wiring to reach it: "Connect: none
 * registered" (inventory). notConnectable() stays: there is still no path,
 * manual or automatic, that populates a mixcloud connection.
 *
 * The detector is NOT a step toward connecting it. Detection and connection
 * are separate questions, and answering only the second left the first
 * answered wrongly: with no rule at all, a mixcloud.com link reached
 * LinkRouter's unclassified arm and spent one of a run's six commerce probes
 * to conclude that mixcloud.com is Mixcloud (N4, 2026-08-11). Recognising the
 * host costs nothing and PlacementPolicy still refuses it as 'unservable'
 * before any threshold is consulted — same shape as ResidentAdvisor.
 */
class Mixcloud
{
    public static function brand(): Brand
    {
        return Brand::make('mixcloud', 'Mixcloud', 'https://www.mixcloud.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('mixcloud.player')
                ->displayName('Mixcloud')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(5)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Music)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->note('dormant until embed set expands, §10')
                ->embed('https://www.mixcloud.com/widget/iframe/?feed={url}', 'fixed:120', [], false)
                // Connectable as a profile link (task #17, 2026-08-18): a
                // Mixcloud profile is mixcloud.com/{handle}; the widget embed
                // stays dormant per §10.
                ->canonicalUrl('https://www.mixcloud.com/{handle}')
                ->detect(
                    Detector::url('mixcloud.com')
                        ->path('#^/(?!discover|upload|pro|about|premium|live|search)(?<handle>[A-Za-z0-9_-]{2,60})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('mixcloud.com')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
