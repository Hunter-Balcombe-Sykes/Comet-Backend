<?php

namespace App\Services\Platforms;

use App\Services\Shop\StoreRecord;
use LogicException;

// The brand-profile resolution split out of ShopController (W9). forDetected()
// is today's fully-synchronous path — moved verbatim, still called from
// addBrand() for every provider. forRow() is the deferred half a later unit's
// ShopBrandConnectJob calls to settle a pending ShopBrand row from its stored
// url/source_url, without re-running detection.
class ShopBrandProfiler
{
    public function __construct(
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly SquarespaceScraper $squarespace,
    ) {}

    /**
     * Resolve the brand profile (and, for generic pages, the products that
     * came with it) for a freshly-detected store.
     *
     * @param  array{provider:string, origin:string, sourceUrl:string, page:array|null,
     *               store:array|null, meta?:array|null, clientBrand?:array, clientProducts?:array,
     *               fetchMode?:string}  $detected
     * @return array{0: array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}, 1: ?array}
     */
    public function forDetected(array $detected): array
    {
        // Client-assisted detection already carries the brand + products the
        // browser fetched — no server round-trips (they'd be blocked anyway).
        if (isset($detected['clientBrand'])) {
            return [$detected['clientBrand'], $detected['clientProducts']];
        }

        return match ($detected['provider']) {
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => [$this->woocommerce->fetchBrand($detected['origin']), null],
            ShopProviderDetector::PROVIDER_SQUARESPACE => [$this->squarespace->fetchBrand($detected['sourceUrl']), null],
            ShopProviderDetector::PROVIDER_BIGCARTEL => [$detected['store'], null],
            ShopProviderDetector::PROVIDER_GENERIC => [$detected['page']['brand'], $detected['page']['products']],
            default => [$this->shopify->fetchBrand($detected['origin']), null],
        };
    }

    /**
     * The one piece of the deferred-branch profile that IS truthfully known
     * at 202 time without an extra fetch: Shopify's shop currency, carried on
     * the detector's 'meta' (W9 §3a). Returns null for every other provider —
     * Woo's fetchBrand() returns currency=>null unconditionally so nothing is
     * lost there, and Squarespace's currency is genuinely only derivable from
     * the deferred pageJson() fetch. Delegates to ShopifyScraper::currencyFrom()
     * (the same expression fetchBrand() uses) rather than reimplementing it —
     * same discipline as ShopBrandIdentity's id derivation.
     *
     * @param  array{provider:string, meta?:array|null}  $detected
     */
    public function syncCurrencyFor(array $detected): ?string
    {
        return $detected['provider'] === ShopProviderDetector::PROVIDER_SHOPIFY
            ? $this->shopify->currencyFrom($detected['meta'] ?? null)
            : null;
    }

    /**
     * Resolve the display profile for an already-stored store — the
     * deferred connect job calls this to settle a pending brand from its
     * truthful url/source_url. bigcartel/generic/client-assisted brands are
     * never pending (§3a of the plan — those three have nothing left to fetch
     * at 202 time), so reaching this for any other provider is a bug upstream.
     *
     * @return array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}
     */
    public function forRow(StoreRecord $store): array
    {
        return match ($store->provider) {
            ShopProviderDetector::PROVIDER_SHOPIFY => $this->shopify->fetchBrand($store->url),
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => $this->woocommerce->fetchBrand($store->url),
            // sourceUrl, NOT source_url — the DTO is camelCase where the column
            // is snake_case, and the plan's claim that the names are identical
            // is wrong for exactly this one.
            ShopProviderDetector::PROVIDER_SQUARESPACE => $this->squarespace->fetchBrand($store->sourceUrl ?? $store->url),
            default => throw new LogicException(
                "ShopBrandProfiler::forRow() is unreachable for provider '{$store->provider}' — only shopify/woocommerce/squarespace ever defer."
            ),
        };
    }
}
