<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;
use Illuminate\Support\Facades\Log;

// V5 OpenTable scraper — extracts the restaurant id (rid) from an OpenTable
// URL and returns the embeddable reservation widget URL. OpenTable WAF-blocks
// server-side fetches (datacenter IPs time out), so no server-side scraping is
// possible — the widget is rendered client-side in the visitor's browser.
//
// Slug links (/r/<slug>) don't contain the rid and can't be resolved
// server-side (WAF). For slug links, the item carries the slug-derived name
// but no embed URL — the caller should prompt the user for the profile link.
class OpenTableScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    /**
     * Parse an OpenTable URL and return reservation widget info.
     *
     * @param string $identifier OpenTable restaurant URL
     * @return array{items: list<array>, profile: array}
     */
    public function fetch(string $identifier): array
    {
        $url = $this->normalizeUrl($identifier);
        $rid = $this->parseRid($url);
        $slugName = $this->nameFromSlug($url);

        $items = [];

        if ($rid !== null) {
            $host = $this->hostOf($url);
            $embedUrl = $this->embedUrl($rid, $host);

            $items[] = [
                'identifier' => 'opentable_'.$rid,
                'name' => $slugName ?? 'OpenTable Restaurant #'.$rid,
                'item_type' => 'service',
                'values' => [
                    ['field_name' => 'embed_url', 'value' => $embedUrl, 'format' => 'url'],
                    ['field_name' => 'restaurant_id', 'value' => $rid, 'format' => 'text'],
                ],
            ];
        } elseif ($slugName !== null) {
            // Slug link — no rid to build a widget URL
            Log::info('v5.opentable.slug_only', [
                'url' => $url,
                'name' => $slugName,
                'message' => 'OpenTable slug link — provide the /restaurant/profile/<rid> URL for the embed widget.',
            ]);

            $items[] = [
                'identifier' => 'opentable_'.md5($url),
                'name' => $slugName,
                'item_type' => 'service',
                'values' => [
                    ['field_name' => 'note', 'value' => 'OpenTable slug link: provide the full profile URL (e.g. opentable.com/restaurant/profile/<rid>) to get an embed widget.', 'format' => 'text'],
                ],
            ];
        } else {
            Log::warning('v5.opentable.unparseable', [
                'url' => $url,
                'message' => 'Could not parse an OpenTable restaurant ID or slug from the URL.',
            ]);
        }

        return ['items' => $items, 'profile' => []];
    }

    /**
     * Extract the restaurant id (rid) from an OpenTable URL.
     */
    private function parseRid(string $url): ?string
    {
        if (preg_match('~opentable\.[a-z.]+/restaurant/profile/(\d+)~i', $url, $m)) {
            return $m[1];
        }
        if (preg_match('~[?&]rid=(\d+)~i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract a display name from a /r/<slug> link.
     */
    private function nameFromSlug(string $url): ?string
    {
        if (preg_match('~opentable\.[a-z.]+/r/([a-z0-9-]+)~i', $url, $m)) {
            $slug = preg_replace('~-\d+$~', '', $m[1]);
            $name = ucwords(str_replace('-', ' ', (string) $slug));

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * Extract the host from an OpenTable URL, defaulting to the AU site.
     */
    private function hostOf(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        return is_string($host) && $host !== '' ? $host : 'www.opentable.com.au';
    }

    /**
     * Generate the keyless OpenTable reservation widget URL.
     */
    private function embedUrl(string $rid, string $host): string
    {
        $tld = preg_replace('~^.*?opentable\.~i', '', $host);
        $domain = str_replace('.', '', (string) $tld) ?: 'com';

        $params = http_build_query([
            'rid' => $rid,
            'domain' => $domain,
            'type' => 'wide',
            'theme' => 'standard',
            'overlay' => 'true',
            'iframe' => 'true',
            'lang' => 'en-AU',
        ]);

        return "https://{$host}/widget/reservation/canvas?{$params}";
    }

    /**
     * Normalize an input URL to https:// form.
     */
    protected function normalizeUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }
}
