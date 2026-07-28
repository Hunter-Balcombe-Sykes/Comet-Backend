<?php

namespace App\Routing\Probes;

use App\Routing\Iri;
use App\Services\Platforms\SquarespaceScraper;

/**
 * The own-domain Squarespace probe (plan §11).
 *
 * Squarespace answers `?format=json` on any page it serves — no key, no auth
 * — and a commerce page's answer carries `collection.typeName == "products"`.
 * Discovery walks the pasted URL first, then the conventional shop paths
 * (/shop, /store, /products), exactly as the legacy detector did.
 *
 * The discovered products-collection URL travels on the evidence as
 * `source_url`: product fetches and refreshes must hit that page, not the
 * homepage, and losing it would mean re-discovering on every sync.
 *
 * Delegates to SquarespaceScraper (P8 deletion list); the id derivation
 * (`idFromOrigin`) is the one fetchBrand() uses, so probe-seeded and
 * wizard-connected stores dedupe to the same brand row.
 */
class SquarespaceStorefrontProbe implements Probe
{
    public function __construct(private readonly SquarespaceScraper $squarespace) {}

    public function name(): string
    {
        return 'squarespace_format_json';
    }

    public function surfaceKey(): string
    {
        return 'squarespace.store';
    }

    public function attempt(Iri $iri): ?array
    {
        $origin = $this->originOf($iri);
        if ($origin === null) {
            return null;
        }

        // The gate only admits storefront-root-ish URLs, so probing from the
        // canonical URL (path included) keeps a pasted /shop page working
        // while the origin fallback paths cover a homepage paste.
        $productsUrl = $this->squarespace->discoverProductsUrl($iri->canonical ?? $origin);
        if ($productsUrl === null) {
            return null;
        }

        // One more fetch on a HIT buys name/currency/logo so the seeder never
        // re-fetches; a miss has already cost nothing extra.
        $brand = $this->squarespace->fetchBrand($productsUrl);

        return [
            'identifier' => $brand['id'],
            'evidence' => [
                'origin' => $this->squarespace->originOf($productsUrl) ?? $origin,
                'source_url' => $productsUrl,
                'shop_name' => $brand['name'],
                'currency' => $brand['currency'],
                'favicon' => $brand['favicon'],
                'logo' => $brand['logo'],
            ],
        ];
    }

    private function originOf(Iri $iri): ?string
    {
        if ($iri->scheme === null || $iri->host === null) {
            return null;
        }

        return $iri->scheme.'://'.$iri->host;
    }
}
