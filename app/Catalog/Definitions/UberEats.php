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
 * Uber Eats — WLH-label ordering brand, new link-only surface. Host from
 * WebsiteLinkHarvester::ORDERING_HOSTS, verbatim.
 *
 * The path pattern (owner, 2026-08-14) — a real store page
 * ("/au/store/<slug>/<id>", live-confirmed against
 * ubereats.com/au/store/ollies-pizza-parlour/HujobRNhRbOGsao8rbYRwA) was
 * scoring 32 on a host-only detector: LinkProjector's base 40 minus its
 * 8-point "deep path, host-only rule" penalty, since a bare
 * `Detector::url('ubereats.com')` has nothing to match the path WITH.
 * RoutingPolicy's `ordering` class needs 55 to even suggest a connection —
 * so every real Uber Eats store link came back Verdict::Note ("we don't
 * recognise this link, add as link") despite the router correctly knowing
 * it was Uber Eats. Optional locale segment (`au`, `gb`, `sg`, seen across
 * Uber Eats' own regional paths) ahead of `/store/<slug>/<id>` — 40 base +
 * 35 path match + 4 strength delta (DeepLinkWithSlug) = 79, clearing the
 * suggest bar with room under the 80 auto-write one.
 */
class UberEats
{
    public static function brand(): Brand
    {
        return Brand::make('uber_eats', 'Uber Eats', 'https://www.ubereats.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('uber_eats.order')
                ->displayName('Uber Eats')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->notConnectable()
                ->detect(
                    Detector::url('ubereats.com')
                        ->path('#^/(?:[a-z]{2}/)?store/(?<slug>[^/?]+)/(?<id>[^/?]+)#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->build(),
        ];
    }
}
