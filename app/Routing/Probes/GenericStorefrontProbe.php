<?php

namespace App\Routing\Probes;

use App\Routing\Iri;
use App\Services\Platforms\GenericShopScraper;

/**
 * The last-resort shop probe (plan §11's "generic"): schema.org Product
 * JSON-LD read off the pasted page itself. Covers Wix, BigCommerce,
 * custom-domain Big Cartel and hand-rolled storefronts that emit standard
 * product markup — no platform API, no auth.
 *
 * This sits LAST in the cascade on purpose: it is the least specific probe,
 * so every platform that can claim the URL more precisely must get its
 * chance first. It is also the one probe that reads general HTML — admitted
 * because schema.org Product markup is a deterministic vocabulary, not a
 * heuristic sniff: a page either declares products or it does not.
 *
 * A probed generic store lands on `partna.storefront` — the reserved
 * shop-probe surface (§1) — because there is no brand to attribute the
 * storefront to.
 */
class GenericStorefrontProbe implements Probe
{
    public function __construct(private readonly GenericShopScraper $generic) {}

    public function name(): string
    {
        return 'generic_jsonld_products';
    }

    public function surfaceKey(): string
    {
        return 'partna.storefront';
    }

    public function attempt(Iri $iri): ?array
    {
        $url = $iri->canonical ?? $iri->raw;
        if ($iri->scheme === null || $iri->host === null) {
            return null;
        }

        // One fetch: page + brand + products come out of the same response.
        // fetchPage() is null both for "unreachable" and "no product markup" —
        // either way this probe has nothing to claim, which is a miss.
        $page = $this->generic->fetchPage($url);
        if ($page === null) {
            return null;
        }

        return [
            'identifier' => (string) $page['brand']['id'],
            'evidence' => [
                'origin' => $iri->scheme.'://'.$iri->host,
                'source_url' => $url,
                'shop_name' => $page['brand']['name'],
                'currency' => $page['brand']['currency'],
                'favicon' => $page['brand']['favicon'],
                'logo' => $page['brand']['logo'],
            ],
        ];
    }
}
