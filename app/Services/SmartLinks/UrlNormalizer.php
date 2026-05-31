<?php

namespace App\Services\SmartLinks;

/**
 * Splits a pasted URL into canonical + tracking pieces (spec §4).
 *
 * `variant` (Shopify variant pin) is discarded. Known affiliate/referral keys
 * are moved to trackingQuery (re-attached to the visitor URL later). Everything
 * else — including essential IDs like Apple's `?i=episodeId` — stays on the
 * canonical URL so extractors can fetch it.
 */
class UrlNormalizer
{
    /** Params we strip + discard entirely. */
    private const DISCARD_KEYS = ['variant'];

    /** Affiliate/referral/analytics keys → moved to trackingQuery. Matched
     *  case-insensitively (keys are lowercased before comparison), so all
     *  entries here must be lowercase. */
    private const TRACKING_KEYS = [
        'sca_ref', 'aff', 'ref', 'affiliate', 'afmc',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'fbclid', 'gclid', 'mc_eid', 'mc_cid', '_branch_match_id',
        // Major affiliate-network click IDs — Refersion, Impact, ShareASale, CJ,
        // Awin, Rakuten, Tapfiliate. Captured so they ride trackingQuery (not the
        // canonical), so we can withhold them on non-Shopify visitor URLs and so
        // our scrape of the canonical doesn't fire a phantom affiliate click.
        'rfsn', 'irclickid', 'sscid', 'cjevent', 'awc', 'clickref', 'ranmid', 'tap_a', 'tap_s',
    ];

    public function parse(string $rawUrl): ParsedUrl
    {
        $rawUrl = trim($rawUrl);
        if (! preg_match('#^https?://#i', $rawUrl)) {
            $rawUrl = 'https://'.ltrim($rawUrl, '/');
        }

        $parts = parse_url($rawUrl);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $tracking = [];
        $essential = [];
        foreach ($query as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, self::DISCARD_KEYS, true)) {
                continue;
            }
            if (in_array($lower, self::TRACKING_KEYS, true)) {
                $tracking[$key] = $value;

                continue;
            }
            $essential[$key] = $value;
        }

        $canonical = "{$scheme}://{$host}".$path;
        if ($essential !== []) {
            $canonical .= '?'.http_build_query($essential);
        }

        $trackingQuery = $tracking === [] ? null : http_build_query($tracking);

        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn ($s) => $s !== ''));

        return new ParsedUrl(
            original: $rawUrl,
            canonical: $canonical,
            trackingQuery: $trackingQuery,
            scheme: $scheme,
            host: $host,
            pathSegments: $segments,
            essentialQuery: $essential,
        );
    }
}
