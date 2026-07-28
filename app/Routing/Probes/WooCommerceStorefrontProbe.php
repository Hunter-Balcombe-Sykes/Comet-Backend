<?php

namespace App\Routing\Probes;

use App\Routing\Iri;
use App\Services\Platforms\WooCommerceScraper;

/**
 * The own-domain WooCommerce probe (plan §11).
 *
 * Every real Woo store is self-hosted on the merchant's own domain — there is
 * no tenant host to detect, ever. The public Store API
 * (`/wp-json/wc/store/v1/products`) is unauthenticated by design and only a
 * WooCommerce store serves it, so a JSON list answer IS the identification.
 *
 * On a hit, one more request (the WP REST root + homepage via fetchBrand)
 * buys the store's display identity so the seeder never has to re-fetch —
 * spent only after the platform has already answered, never on a miss.
 *
 * Delegates to WooCommerceScraper (P8 deletion list) so the endpoint's two
 * URL forms and the brand-id derivation live in exactly one place.
 */
class WooCommerceStorefrontProbe implements Probe
{
    public function __construct(private readonly WooCommerceScraper $woocommerce) {}

    public function name(): string
    {
        return 'woocommerce_store_api';
    }

    public function surfaceKey(): string
    {
        return 'woocommerce.store';
    }

    public function attempt(Iri $iri): ?array
    {
        $origin = $this->originOf($iri);
        if ($origin === null) {
            return null;
        }

        if (! $this->woocommerce->probe($origin)) {
            return null;
        }

        // brandIdFor is the SAME expression fetchBrand() keys on — a probed
        // store and a wizard-connected one dedupe to the same brand row.
        $brand = $this->woocommerce->fetchBrand($origin);

        return [
            'identifier' => $this->woocommerce->brandIdFor($origin),
            'evidence' => [
                'origin' => $origin,
                'shop_name' => $brand['name'],
                'currency' => $brand['currency'],
                'favicon' => $brand['favicon'],
                'logo' => $brand['logo'],
            ],
        ];
    }

    /** Probe endpoints hang off the origin, never the pasted path. */
    private function originOf(Iri $iri): ?string
    {
        if ($iri->scheme === null || $iri->host === null) {
            return null;
        }

        return $iri->scheme.'://'.$iri->host;
    }
}
