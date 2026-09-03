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
 * Tablein — WLH-label reservations brand, new link-only surface. Host from
 * WebsiteLinkHarvester::RESERVATION_HOSTS (verbatim); since Phase 6 WLH returns
 * this surface key ('tablein.reserve') rather than a generic bucket.
 *
 * The restaurant-facing widget is NOT on the apex — it's on the
 * widget.tablein.com subdomain, at /widget/<slug> (optionally locale-
 * prefixed), confirmed live via Tablein's own widget-integration help page
 * (a captured "Widget link" field) and the widget itself:
 * https://widget.tablein.com/en/widget/yolkhaus?widget=5456 renders
 * YOLKHAÜS's real booking page; the bare widget.tablein.com root does not.
 */
class Tablein
{
    public static function brand(): Brand
    {
        return Brand::make('tablein', 'Tablein', 'https://www.tablein.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tablein.reserve')
                ->displayName('Tablein')
                ->routing(RoutingClass::Reservations)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('tablein.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('tablein.com')
                        ->subdomain('#^widget$#')
                        ->path('#^/(?:[a-z]{2}/)?widget/(?<slug>[^/?]+)#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://widget.tablein.com/en/widget/yolkhaus?widget=5456'),
                )
                ->build(),
        ];
    }
}
