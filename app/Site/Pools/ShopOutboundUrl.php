<?php

namespace App\Site\Pools;

/**
 * The outbound product link — what a visitor's click actually opens.
 *
 * Moved backend-side in slice 5b (owner decision 2026-08-12). It used to live in
 * partna-monorepo's productHref(); composing it here makes referral revenue
 * testable in this repo, and a change to the site's link mode takes effect on
 * the next payload build with nothing to re-backfill.
 *
 * The format is RECOVERED from this repository's own history, not reconstructed
 * from convention — ac462b2d6 (2026-07-07), the commit that built the feature:
 * "link_mode ('product'|'checkout'): rides the public payload so the sitepage
 * can deep-link carts (Shopify /cart/{variant}:1?discount=, Woo ?add-to-cart=)".
 * Corroborated by edf71f545 (2026-07-08).
 *
 * Pure on purpose: dev exercises none of this (all 32 sites are 'checkout', all
 * 9 stores carry referral_query = '', no product URL has a query string), so the
 * unit tests are the ONLY place this behaviour is verified.
 */
final class ShopOutboundUrl
{
    /**
     * @param  string  $bareUrl  content.f_link.url — bare and uncomposed
     * @param  string  $linkMode  site.sites.shop_link_mode; anything unrecognised is 'checkout' (the column default)
     * @param  object|null  $store  a content.storefronts row: provider, url, discount_code, referral_query
     * @param  string|null  $variantRef  content.f_catalog.variant_ref — the provider's checkout id
     */
    public static function compose(string $bareUrl, string $linkMode, ?object $store, ?string $variantRef): string
    {
        $url = $bareUrl;
        $ref = trim((string) $variantRef);
        $provider = strtolower(trim((string) ($store->provider ?? '')));

        if ($linkMode !== 'product' && $store !== null && $ref !== '') {
            $storeUrl = rtrim(trim((string) ($store->url ?? '')), '/');

            $url = match ($provider) {
                // Shopify's permalink cart: one unit of one variant.
                'shopify' => $storeUrl !== '' ? "{$storeUrl}/cart/{$ref}:1" : $bareUrl,
                // Woo adds to the cart from the product page itself.
                'woocommerce' => self::append($bareUrl, "add-to-cart={$ref}"),
                // No documented deep-link form for squarespace / bigcartel /
                // generic — the product page is the honest destination.
                default => $bareUrl,
            };
        }

        $discount = trim((string) ($store->discount_code ?? ''));
        if ($discount !== '') {
            $url = self::append($url, 'discount='.rawurlencode($discount));
        }

        // A whole key=value pair (e.g. "ref=abc"), not a bare code — the shape
        // ShopController::referralQueryFrom() and UrlParamExtractor::extract()
        // both store, capped at 500 chars. Appended in BOTH link modes: it is
        // affiliate attribution, not a checkout artefact.
        $referral = trim((string) ($store->referral_query ?? ''));
        if ($referral !== '') {
            $url = self::append($url, $referral);
        }

        return $url;
    }

    /** Join a query fragment with ? or &, whichever the URL still needs. */
    private static function append(string $url, string $fragment): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').$fragment;
    }
}
