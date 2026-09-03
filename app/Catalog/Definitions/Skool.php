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
 * Skool — link-only since the 2026-08-16 demotion. PD-retirement P2
 * (2026-08-27): the surface became CONNECTABLE — the P1-era
 * notConnectable() reflected a bespoke controller that the demotion
 * deleted, and the catalog now drives the connection through the derived
 * LinkOnly descriptor (LinkOnlyBindings: UrlConnect + SkoolNormalizer,
 * `url` field, the historical 422 copy). refreshEvery(0) — nothing left
 * to scrape.
 *
 * The earlier "Detect: none" ground truth is DELIBERATELY superseded here
 * (owner, 2026-09-03): every connectable, active platform must carry the
 * link format(s) it accepts, so that the manual and auto lanes ask the same
 * validity question and neither can save a link we cannot recognise.
 * Detect-none left this surface connectable with no way to route or
 * validate a pasted Skool link at all — it answered 'unknown-domain'.
 * Scraping is still gone; a detector is a routing rule, not a fetcher.
 */
class Skool
{
    public static function brand(): Brand
    {
        return Brand::make('skool', 'Skool', 'https://www.skool.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('skool.community')
                ->legacyPlatform('skool')
                ->displayName('Skool')
                // Not a limited kind of link (owner, 2026-08-19): a content or events
                // page is one of several a person may run — the 1-account default is for
                // bookings/reservations/ordering (one provider) and socials (one profile).
                ->multiAccount(10)
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Community)
                ->identifier(IdentifierKind::Slug)
                ->refreshEvery(0)
                ->connect('connect.skool.url.v1')
                ->note('link-only since 2026-08-16 (Phase 1.2): UrlConnect + SkoolNormalizer, no fetch, no refresh — the bespoke SkoolController and its scraper were deleted with the demotion')
                ->canonicalUrl('https://www.skool.com/{handle}')
                // Had NO detector at all until 2026-09-03, so a pasted Skool
                // link answered 'unknown-domain' on every lane while the
                // surface sat connectable and active with a canonicalUrl
                // template right above. Found by the real-URL sweep, not by a
                // synthesised one — a generated corpus can only exercise
                // patterns that exist, so a missing detector is invisible to it.
                //
                // Reserved routes are rejected anchored on `(?:/|$)`, which is
                // what keeps a community whose slug merely STARTS with one of
                // them ('community-starters-1391', live) out of the reject.
                // Every route in the list and both community shapes were
                // probed live on 2026-09-03.
                ->detect(
                    Detector::url('skool.com')
                        ->path('#^/(?<handle>[a-z0-9][a-z0-9-]{1,63})(?:/(?:about|classroom|calendar|members|leaderboard|map))?/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:login|signup|about|pricing|discovery|games|refer|careers|community|help|terms|privacy|cookies|blog|settings|search|explore|home|checkout|oauth2?|api)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://www.skool.com/skool-100 and https://www.skool.com/community-starters-1391 — both verified live (HTTP 200) 2026-09-03'),
                )
                ->build(),
        ];
    }
}
