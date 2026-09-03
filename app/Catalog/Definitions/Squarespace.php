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
 * Squarespace commerce. A store on the merchant's OWN domain carries no host
 * signal at all — there the commerce probe (§11) does the identifying, since
 * every Squarespace page answers `?format=json` and a products collection in
 * that answer IS the store.
 *
 * But "so the only detector is the marketing host" (the reasoning here until
 * 2026-09-03) skipped the other half: every Squarespace site also keeps its
 * built-in <site>.squarespace.com address, which is what a merchant without a
 * custom domain actually pastes. That subdomain IS the site name, so it is
 * captured rather than left to file the whole URL as the resource_id. The
 * own-domain case is unchanged and still WEAK by necessity — unlike
 * WooCommerce, which has no hosted address at all and so stays WEAK outright.
 */
class Squarespace
{
    public static function brand(): Brand
    {
        return Brand::make('squarespace', 'Squarespace', 'https://www.squarespace.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('squarespace.store')
                // F7 (2026-08-20): in lockstep with the shop family's
                // MAX_BRANDS (10, T9) — the catalog's default of 1 was
                // blocking Engine-1 store placements at ONE store while every
                // other door allowed ten (caught live: the046.com).
                ->multiAccount(10)
                ->displayName('Squarespace store')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('squarespace.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('squarespace.com')
                        ->subdomain('#^(?!(?:www|account|login|assets|images|static|videos|developers|support|help|status|blog|forum|templates|fonts|careers|news|trust|domains)$)(?<site>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('site')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://altiba9.squarespace.com/shop and https://angelinavillalobos.squarespace.com/shop — both verified live (HTTP 200) 2026-09-03. ProfileLink, not DeepLinkWithSlug: the subdomain names the SITE, and whether that site sells anything is the probe\'s question.'),
                )
                ->note('listed so shop routing has a surface for probed Squarespace stores; own-domain storefronts carry no host signal — the commerce probe (§11) does the connecting')
                ->build(),
        ];
    }
}
