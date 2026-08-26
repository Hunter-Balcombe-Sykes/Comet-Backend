<?php

namespace App\Services\Platforms;

/**
 * Deterministic storefront-tech markers for the platforms we can connect as
 * a brand — asset/runtime signatures only (never prose mentioning a
 * platform), so a positive means a brand connect is likely to work.
 *
 * Extracted from GenericShopScraper::looksLikeStorefront() (T4, 2026-08-20)
 * so the Links preview can answer "this page IS a storefront — offer the
 * connect" from the HTML it already fetched, without a second read and
 * without the two marker lists drifting.
 */
final class StorefrontMarkers
{
    /** provider key => human label, matching the connectable brand names. */
    public const LABELS = [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
        'squarespace' => 'Squarespace',
        'bigcartel' => 'Big Cartel',
        'square-online' => 'Square Online',
    ];

    /**
     * A provider whose value is a LIST requires EVERY pattern to match —
     * square-online needs the pairing because plain Weebly sites share the
     * editmysite CDN marker; only the commerce runtime
     * (__BOOTSTRAP_STATE__/store_mode/store api) separates an ordering
     * storefront from a brochure site on the same platform (A0.3,
     * live-verified 2026-08-26 on square.site AND custom domains).
     */
    private const SIGNATURES = [
        'shopify' => '~cdn\.shopify\.com|/cdn/shop/|window\.Shopify|Shopify\.theme~i',
        'woocommerce' => '~plugins/woocommerce|class=["\'][^"\']*\bwoocommerce~i',
        'squarespace' => '~Static\.SQUARESPACE_CONTEXT|assets\.squarespace\.com~i',
        'bigcartel' => '~bigcartel\.com~i',
        'square-online' => [
            '~cdn\d*\.editmysite\.com~i',
            '~__BOOTSTRAP_STATE__|"store_mode"|/app/store/api/~',
        ],
    ];

    /** The provider whose runtime signature this HTML carries, or null. */
    public static function detect(string $html): ?string
    {
        foreach (self::SIGNATURES as $provider => $patterns) {
            $matched = true;
            foreach ((array) $patterns as $pattern) {
                if (preg_match($pattern, $html) !== 1) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $provider;
            }
        }

        return null;
    }
}
