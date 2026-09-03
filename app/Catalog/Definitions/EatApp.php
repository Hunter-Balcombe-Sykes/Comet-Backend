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
 * Eat App. New link-only surface — today a
 * WebsiteLinkHarvester::RESERVATION_HOSTS label ('Eat App',
 * WebsiteLinkHarvester.php:67) that collapses into the generic
 * 'reservations' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand.
 *
 * "Host-only detector, no capture" was true until 2026-09-03, when the
 * real-URL sweep found the restaurant page is a single top-level slug —
 * eatapp.co/<restaurant>. That is grammar, so the surface no longer sits in
 * the L1-WEAK bucket where a booking link files the whole URL as the
 * account's resource_id.
 */
class EatApp
{
    public static function brand(): Brand
    {
        return Brand::make('eat_app', 'Eat App', 'https://www.eatapp.co');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('eat_app.reserve')
                ->displayName('Eat App')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('eatapp.co')->strength(EvidenceStrength::ProfileLink),
                    // The restaurant page is one segment under an OPTIONAL
                    // locale prefix — eatapp.co/<slug> and eatapp.co/en/<slug>
                    // are both live. The prefix is the Deliveroo defect's exact
                    // shape (a pattern written against one market's URLs that
                    // silently refuses every other market's), so it is declared
                    // rather than discovered later.
                    //
                    // The slug is free-form, so only a reject list can tell it
                    // from a marketing page. That list carries the same optional
                    // prefix: anchoring it at '^/' would let /en/pricing through
                    // the door /pricing is held shut at. Minimum three characters
                    // is what stops a bare '/en' being read as a restaurant.
                    Detector::url('eatapp.co')
                        ->path('#^(?:/[a-z]{2}(?:-[a-z]{2})?)?/(?<slug>[a-z0-9][a-z0-9-]{2,120})/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->reject('#^(?:/[a-z]{2}(?:-[a-z]{2})?)?/(?:blog|pricing|about|contact|careers|demo|book-a-demo|request-demo|login|log-in|signup|sign-up|features?|resources|integrations|partners|terms|privacy|cookies|legal|help|support|docs|api|status|press|security|solutions|products?|customers|restaurants|hotels|search|home|sitemap)(?:/|$)#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://eatapp.co/rm-38-waikiki-99383d and https://eatapp.co/en/rm-38-waikiki-99383d — both verified live (HTTP 200) 2026-09-03'),
                )
                ->build(),
        ];
    }
}
