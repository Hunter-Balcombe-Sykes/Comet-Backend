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
 * Vagaro — 27-set booking. A business's own page is a bare first-level
 * slug, vagaro.com/<slug> (confirmed live via a real Dallas hair-salon
 * directory listing: /pinktoesnailbar, /chicuts, /vibesalondfw, ... —
 * real professionals' pages, one subpath deep e.g. /pinktoesnailbar/staff).
 * This is the most dangerous shape in the catalog: it matches every
 * first-level marketing path too, so every reject entry below was checked
 * live with curl against www.vagaro.com and returns 200 (a real Vagaro
 * route, not a 404) — /pro, /login, /signup, /help, /support, /blog,
 * /business, /careers, /salon-software, /learn, /professionals,
 * /listings, plus /deals, /photos, /live-stream-classes (403 to a
 * scripted client, but live routes, not 404s). /pricing, /features,
 * /about, /customers, /join were 404 at check time but are kept in the
 * reject list defensively (harmless if unused, cheap insurance if Vagaro
 * adds them back).
 */
class Vagaro
{
    public static function brand(): Brand
    {
        return Brand::make('vagaro', 'Vagaro', 'https://www.vagaro.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('vagaro.book')
                ->legacyPlatform('vagaro')
                ->displayName('Vagaro')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('vagaro.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('vagaro.com')
                        ->path('#^/(?<slug>[a-z0-9][a-z0-9-]{1,89})(?:/[a-z0-9-]{1,64})?/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:pro|login|signup|help|support|blog|business|careers|salon-software|learn|professionals|listings|deals|photos|live-stream-classes|pricing|features|about|customers|join|sales|api|app|admin|status)(?:/|$)#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.vagaro.com/pinktoesnailbar'),
                )
                ->build(),
        ];
    }
}
