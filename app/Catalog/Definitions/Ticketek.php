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
 * Ticketek — events/ticketing, detect-only, enumerated TLD loop (PRSP:451,
 * verbatim). MarketplaceListing strength: ticket sellers, not profiles.
 */
class Ticketek
{
    /**
     * Single source of truth for this brand's regional TLDs — consumed by
     * WebsiteLinkHarvester::classify() and ItemLinkRules, never re-listed.
     *
     * @var list<string>
     */
    public const TLDS = ['com', 'com.au', 'co.nz', 'com.ar'];

    public static function brand(): Brand
    {
        return Brand::make('ticketek', 'Ticketek', 'https://www.ticketek.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ticketek.tickets')
                ->legacyPlatform('ticketek')
                ->displayName('Ticketek')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(10)
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("ticketek.{$tld}")->strength(EvidenceStrength::MarketplaceListing),
                        self::TLDS,
                    ),
                    // The show code lives in ?sh= on every Ticketek show page
                    // (premier.ticketek.<tld>/Shows/Show.aspx?sh=<code>).
                    //
                    // Honest limit: Ticketek 403s every automated request — its
                    // own homepage included — so unlike the other patterns added
                    // in this sweep, this shape could NOT be confirmed by
                    // fetching it. It rests on two independent sightings whose
                    // codes decode sensibly (ABEAUTN26 = A Beautiful Noise 2026,
                    // PRETTYW26 = Pretty Woman 2026).
                    //
                    // That distinction MATTERS here, and it is not the harmless
                    // one it first looks like. A detector that captures nothing
                    // is inert when wrong; this one is not, because
                    // LinkValidity::shapeFor() treats ONE specific detector as
                    // enough to start refusing every other shape on the host
                    // (BrandLinkConnect::shapeRefusal). So declaring a shape
                    // here is a claim about what a Ticketek link looks like, and
                    // a wrong claim would refuse real links rather than merely
                    // failing to enrich them.
                    //
                    // Kept because the evidence is strong and the alternative —
                    // leaving the surface shapeless — files every Ticketek link
                    // as a whole-URL resource_id. Residual risk, stated rather
                    // than papered over: Ticketek's venue and tour pages are
                    // other real shapes, unverifiable from here, and a paste of
                    // one now gets the refusal hint pointing at the show form.
                    // Add them as evidence appears; do not guess them.
                    ...array_map(
                        fn (string $tld) => Detector::url("ticketek.{$tld}")
                            ->query('sh')
                            ->captures('sh')
                            ->from(IdentifierSource::Query)
                            ->strength(EvidenceStrength::DeepLinkWithSlug)
                            ->note("e.g. https://premier.ticketek.{$tld}/Shows/Show.aspx?sh=ABEAUTN26 — shape seen in the wild; unverifiable by fetch (site 403s automation)"),
                        self::TLDS,
                    ),
                )
                ->build(),
        ];
    }
}
