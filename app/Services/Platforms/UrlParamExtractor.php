<?php

namespace App\Services\Platforms;

/**
 * Pulls a discount code and a referral param out of a scanned URL's query
 * string, so a store link someone put in their Instagram bio keeps the code it
 * was shared with (2026-07-25, Phase 12).
 *
 * Called from ShopBrandSeeder against $detected['sourceUrl'] — the URL as
 * scanned, before ShopProviderDetector normalises it to an origin. Both values
 * are only ever applied to a NEW brand row; a code the user typed themselves is
 * never clobbered by a re-scrape.
 *
 * No DDL was needed for either field: `discount_code` already exists, and the
 * referral half reuses `referral_query` — the same `key=value` suffix
 * ShopController::referralQueryFrom() writes and productHref() appends, which is
 * why extract() returns the whole pair rather than a bare code. That sidesteps
 * the ALTER + view drop/recreate that a new column on site.shop_brands would
 * have forced (the view enumerates its columns explicitly).
 */
final class UrlParamExtractor
{
    /** Query param names that carry a discount code, in precedence order. */
    private const DISCOUNT_PARAMS = ['discount', 'code', 'coupon', 'promo', 'voucher'];

    /** Query param names that carry a referral/affiliate code, in precedence order. */
    private const REFERRAL_PARAMS = ['ref', 'referral', 'referrer', 'affiliate', 'partner'];

    /** Mirrors ShopController::referralQueryFrom()'s cap on the stored suffix. */
    private const MAX_REFERRAL_QUERY = 500;

    /**
     * @return array{discountCode: ?string, referralQuery: ?string} referralQuery
     *                                                              is the matched `key=value` pair (e.g. "ref=abc"), not a bare code —
     *                                                              that is the shape site.shop_brands.referral_query stores.
     */
    public static function extract(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return ['discountCode' => null, 'referralQuery' => null];
        }

        parse_str($query, $params);

        $discount = self::firstValue($params, self::DISCOUNT_PARAMS);
        $referralKey = self::firstKey($params, self::REFERRAL_PARAMS);

        return [
            'discountCode' => $discount,
            'referralQuery' => $referralKey === null
                ? null
                : mb_substr($referralKey.'='.rawurlencode((string) self::firstValue($params, [$referralKey])), 0, self::MAX_REFERRAL_QUERY),
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @param  list<string>  $keys
     */
    private static function firstValue(array $params, array $keys): ?string
    {
        $key = self::firstKey($params, $keys);

        return $key === null ? null : trim((string) $params[$key]);
    }

    /**
     * @param  array<string,mixed>  $params
     * @param  list<string>  $keys
     */
    private static function firstKey(array $params, array $keys): ?string
    {
        foreach ($keys as $key) {
            // is_string excludes the array form (?ref[]=a) — parse_str hands
            // those back as arrays and (string) on one is a warning, not a code.
            if (isset($params[$key]) && is_string($params[$key]) && trim($params[$key]) !== '') {
                return $key;
            }
        }

        return null;
    }
}
