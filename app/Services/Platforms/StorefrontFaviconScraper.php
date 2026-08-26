<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

/**
 * The storefront's favicon, for a probe lane that did not already carry one.
 *
 * Three of the five commerce probes (Woo, Squarespace, Generic) read the
 * storefront homepage to identify it, so the favicon comes along free in
 * evidence['favicon']. Shopify reads only /meta.json and Big Cartel only its
 * store API — deliberately, to keep those probes to one cheap request — so
 * they carry no favicon at all, and content.storefronts.favicon_url stayed
 * permanently NULL for every store connected through them. That blanks the
 * Platforms table icon too, not just the suggestion card, since
 * ShopBrandResource serves the same column.
 *
 * Deliberately NOT folded into those probes. A probe runs against many
 * candidate URLs, most of which never become stores, and its per-run budget is
 * the scarce resource; paying a second request there buys favicons that are
 * mostly thrown away. StoreBrandSeeder calls this only when a store is
 * actually being written, which is rare and already off the probe budget.
 *
 * Extends PlatformScraper purely to reuse favicon()/originOf() — the same
 * scoring the other three lanes use, so a Shopify store's favicon is chosen
 * the way a Woo store's is.
 */
class StorefrontFaviconScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** The best declared favicon on the storefront's homepage, or null. */
    public function fetch(string $url): ?string
    {
        $origin = $this->originOf($url);
        if ($origin === null) {
            return null;
        }

        // tryFetch() returns null on a transport-level failure (unresolvable
        // host, SSRF rejection, timeout) — guard it before touching the array,
        // else the null deref becomes a 500 (WS-B1, same guard as
        // WooCommerceScraper::fetchBrand()).
        $home = $this->fetcher->tryFetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
        if ($home === null || $home['status'] !== 200) {
            return null;
        }

        return $this->favicon((string) $home['body'], $origin);
    }
}
