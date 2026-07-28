<?php

namespace App\Routing\Probes;

use App\Routing\Iri;
use App\Services\Platforms\ShopifyScraper;

/**
 * The own-domain Shopify probe — the first of §11's five keyless commerce
 * probes to gain a runtime.
 *
 * `*.myshopify.com` already has a detector, because the host says so. A
 * merchant's own domain does not: `shop.example.com` is indistinguishable from
 * any other site until something asks it. `/meta.json` is served only by
 * Shopify storefronts and needs no key, so a well-formed answer is the
 * identification.
 *
 * This deliberately DELEGATES to ShopifyScraper::probeMeta() rather than
 * reimplementing the request. That endpoint is undocumented; when it drifts,
 * one expression must change, not two. The scraper is on P8's deletion list —
 * whoever deletes it moves probeMeta() here and finds this class already
 * shaped to receive it.
 */
class ShopifyStorefrontProbe implements Probe
{
    public function __construct(private readonly ShopifyScraper $shopify) {}

    public function name(): string
    {
        return 'shopify_meta_json';
    }

    public function surfaceKey(): string
    {
        return 'shopify.store';
    }

    public function attempt(Iri $iri): ?array
    {
        $origin = $this->originOf($iri);
        if ($origin === null) {
            return null;
        }

        $meta = $this->shopify->probeMeta($origin);
        if ($meta === null) {
            return null;
        }

        return [
            // The shop id when Shopify gave us one, else a slug of the host —
            // the same expression ShopBrandIdentity uses, so a probed store and
            // a wizard-connected one dedupe to the same connection.
            'identifier' => $this->shopify->brandIdFrom($meta, $origin),
            // The decoded body rides forward: the seeder needs the shop name
            // and currency, and re-fetching /meta.json to get them would double
            // the probe's cost for no new information.
            'evidence' => [
                'origin' => $origin,
                'shop_name' => is_string($meta['name'] ?? null) ? $meta['name'] : null,
                'currency' => $this->shopify->currencyFrom($meta),
                'myshopify_domain' => is_string($meta['myshopify_domain'] ?? null) ? $meta['myshopify_domain'] : null,
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
