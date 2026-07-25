<?php

namespace App\Services\Platforms;

/**
 * Extracts discount and referral codes from URL query parameters.
 * Used when auto-seeding shop/store connections from scanned links.
 */
final class UrlParamExtractor
{
    /** Query param names that carry a discount code. */
    private const DISCOUNT_PARAMS = ['discount', 'code', 'coupon', 'promo', 'voucher'];

    /** Query param names that carry a referral/affiliate code. */
    private const REFERRAL_PARAMS = ['ref', 'referral', 'referrer', 'affiliate', 'partner'];

    /** @return array{discountCode: ?string, referralCode: ?string} */
    public static function extract(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return ['discountCode' => null, 'referralCode' => null];
        }

        parse_str($query, $params);

        $discount = null;
        foreach (self::DISCOUNT_PARAMS as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && trim($params[$key]) !== '') {
                $discount = trim($params[$key]);

                break;
            }
        }

        $referral = null;
        foreach (self::REFERRAL_PARAMS as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && trim($params[$key]) !== '') {
                $referral = trim($params[$key]);

                break;
            }
        }

        return ['discountCode' => $discount, 'referralCode' => $referral];
    }
}
