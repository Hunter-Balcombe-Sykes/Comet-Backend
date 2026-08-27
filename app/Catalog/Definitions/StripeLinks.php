<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Stripe payment links — detect-only (wave 2, 2026-08-28). buy.stripe.com
 * slugs are opaque, so the win is recognition: a payment link routes as a
 * shop link instead of spending a commerce probe concluding that
 * buy.stripe.com is Stripe (the Mixcloud N4 rule). stripe.com itself is
 * NEVER matched — dashboard/docs links are not a storefront.
 */
class StripeLinks
{
    public static function brand(): Brand
    {
        return Brand::make('stripe', 'Stripe', 'https://stripe.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('stripe.payment_link')
                ->displayName('Stripe')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Url)
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('stripe.com')
                        ->subdomain('#^buy$#')
                        ->path('#^/(?:test_)?[A-Za-z0-9]{8,64}/?$#')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
