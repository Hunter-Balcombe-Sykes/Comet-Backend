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
 * Toast — WLH-label ordering brand ("Toast Takeout" in WLH's label set), new
 * link-only surface. Real host is toasttab.com, NOT toast.com
 * (WebsiteLinkHarvester::ORDERING_HOSTS, verbatim).
 *
 * The shareable public-ordering link Toast itself tells restaurants to hand
 * out is a BARE single segment — www.toasttab.com/<slug> — confirmed live
 * (301s to order.toasttab.com/online/<slug>). That bare shape is deliberately
 * NOT detected here: toasttab.com's marketing content lives on sibling
 * subdomains under the same registrable key (pos.toasttab.com,
 * careers.toasttab.com, support.toasttab.com, community.toasttab.com) which
 * this catalog's subdomain_pattern cannot exclude (it can only require a
 * specific non-null subdomain, never "no subdomain") — and those subdomains
 * carry their own single-segment marketing paths (confirmed live:
 * pos.toasttab.com/pricing, careers.toasttab.com/homepage), so a bare
 * cross-subdomain single-segment rule would misread them as restaurant
 * slugs. Only the subdomain-scoped order.toasttab.com/online/<slug> form is
 * added, which is safe on both axes (subdomain AND a literal path prefix).
 */
class Toast
{
    public static function brand(): Brand
    {
        return Brand::make('toast', 'Toast', 'https://pos.toasttab.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('toast.order')
                ->displayName('Toast')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('toasttab.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('toasttab.com')
                        ->subdomain('#^order$#')
                        ->path('#^/online/(?<slug>[\w-]+)/?$#i')
                        ->captures('slug')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://order.toasttab.com/online/toast-trattoria-omaha (the www.toasttab.com/toast-trattoria-omaha public link 301s here)'),
                )
                ->build(),
        ];
    }
}
