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
 * PayPal.Me — link-only tip/payment page (wave 2, 2026-08-28). Both the
 * short host and the canonical paypal.com/paypalme/ path are one identity;
 * paypal.com gets NO bare-host fallback on purpose — checkout and invoice
 * links on that host are not a profile. Verified via the 301
 * paypal.me/{user} → paypal.com/paypalme/{user}.
 */
class Paypal
{
    public static function brand(): Brand
    {
        return Brand::make('paypal', 'PayPal', 'https://www.paypal.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('paypal.me')
                ->displayName('PayPal')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Detect-only (wave 2): links route from scanned bios; the legacy
                // harvester carries no classify() entry, so a manual connect card
                // would 422 its own URL (BrandCoverageTest). Card comes later with
                // harvester support, if ever needed.
                ->notConnectable()
                ->refreshEvery(0)
                ->canonicalUrl('https://paypal.me/{handle}')
                ->detect(
                    Detector::url('paypal.me')
                        ->path('#^/(?<handle>[A-Za-z0-9]{2,40})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->reject('#^/(?:home|signin|signup|my|smarthelp)(?:/|$)#i')
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('paypal.com')
                        ->path('#^/paypalme/(?<handle>[A-Za-z0-9]{2,40})/?$#i')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
