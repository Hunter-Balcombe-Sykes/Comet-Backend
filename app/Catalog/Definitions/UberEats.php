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
                // Connectable since convergence Phase 6: this surface replaces
                // the `online-ordering` pseudo-platform as the home for an Uber
                // Eats store link, and it is the source a menu connector runs
                // against. Single-account by deliberate omission of
                // multiAccount() — owner ruling 2026-08-16: a second Uber Eats
                // store for one user becomes a links-pool item rather than
                // widening the `order:{platform}` collection natural key.
                // Locale alternation widened and the `i` flag added 2026-09-01,
                // so this agrees with config('partna.menu.platforms.uber-eats
                // .store_path_pattern') — the OTHER place that decides whether
                // an Uber Eats URL is a store. They disagreed twice over, on
                // form AND on case: `[a-z]{2}` case-sensitive matched neither
                // half of `en-AU`, the exact locale shape the sibling DoorDash
                // link uses (`/en-CA/store/…`). So /en-AU/store/<slug>/<id> was
                // a store to the scrape guard and an unrecognised link here,
                // scoring host-only, landing under RoutingPolicy's 55-point
                // ordering bar, and returning Verdict::Note instead of a
                // connection. A lost store, not a safety margin — and the
                // guzman-y-gomez failure reached from the other end, where the
                // two spellings' disagreement is the bug either way.
                // Widening is the safe direction here: a locale form Uber never
                // serves simply never arrives, whereas rejecting one it does
                // serve costs a menu. UberEatsStorePathAgreementTest fails if
                // they drift apart again, and pins the ONE difference that may
                // remain (the <slug>/<id> pair, which this detector needs to
                // capture an identifier and the config key does not).
                ->detect(
                    Detector::url('ubereats.com')
                        ->path('#^/(?:[a-z]{2}(?:-[a-z]{2})?/)?store/(?<slug>[^/?]+)/(?<id>[^/?]+)#i')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->build(),
        ];
    }
}
