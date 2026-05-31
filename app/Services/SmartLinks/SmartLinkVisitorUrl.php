<?php

namespace App\Services\SmartLinks;

use App\Models\Core\Site\SmartLink;

/**
 * Builds the visitor-facing click target from a stored smart link: canonical
 * URL + preserved tracking params + (where supported) a discount-code wrapper
 * that auto-applies at checkout (spec §4).
 *
 *   - Shopify: https://host/discount/CODE?redirect=<path+tracking>
 *   - Eventbrite: canonical + ?discount=CODE (+ tracking)
 *   - everything else: canonical + tracking
 */
class SmartLinkVisitorUrl
{
    public function build(SmartLink $link): string
    {
        $canonical = (string) $link->canonical_url;
        $tracking = $link->tracking_query ? (string) $link->tracking_query : null;
        $code = $link->discount_code ? trim((string) $link->discount_code) : null;

        $parts = parse_url($canonical);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $existingQuery = $parts['query'] ?? '';

        // Shopify discount wrapper — applies the code at checkout, lands on the page.
        if ($code !== null && $link->platform === 'shopify' && $host !== '') {
            $redirectTarget = $path;
            $redirectQuery = $this->mergeQuery($existingQuery, $tracking);
            if ($redirectQuery !== '') {
                $redirectTarget .= '?'.$redirectQuery;
            }

            return "{$scheme}://{$host}/discount/".rawurlencode($code).'?redirect='.rawurlencode($redirectTarget);
        }

        // Eventbrite discount param.
        if ($code !== null && $link->platform === 'eventbrite') {
            $query = $this->mergeQuery($existingQuery, $tracking, ['discount' => $code]);

            return $this->withQuery($scheme, $host, $path, $query);
        }

        // Default: canonical + tracking.
        $query = $this->mergeQuery($existingQuery, $tracking);

        return $this->withQuery($scheme, $host, $path, $query);
    }

    /** @param array<string,string> $extra */
    private function mergeQuery(string $existing, ?string $tracking, array $extra = []): string
    {
        $parts = array_filter([$existing, $tracking]);
        $query = implode('&', $parts);
        if ($extra !== []) {
            $query = ($query !== '' ? $query.'&' : '').http_build_query($extra);
        }

        return $query;
    }

    private function withQuery(string $scheme, string $host, string $path, string $query): string
    {
        $url = "{$scheme}://{$host}{$path}";

        return $query !== '' ? "{$url}?{$query}" : $url;
    }
}
