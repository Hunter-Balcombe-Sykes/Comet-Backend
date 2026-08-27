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
 * ProductReview.com.au — link-only business listing (wave 2, 2026-08-28).
 * /listings/your-business-listing is a permanent demo page shaped exactly
 * like a real slug — excluded by lookahead. Verified example:
 * productreview.com.au/listings/showpo.
 */
class ProductReviewAu
{
    public static function brand(): Brand
    {
        return Brand::make('productreview', 'ProductReview.com.au', 'https://www.productreview.com.au');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('productreview.listing')
                ->displayName('ProductReview.com.au')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Business)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://www.productreview.com.au/listings/{handle}')
                ->detect(
                    Detector::url('productreview.com.au')
                        ->path('#^/listings/(?!your-business-listing(?:/|$))(?<handle>[a-z0-9-]+)/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
