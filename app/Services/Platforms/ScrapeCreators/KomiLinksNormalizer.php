<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b: ScrapeCreators' /v1/komi returns the page's module tiles as one
// flat rows list plus the icon-row socials as TOP-LEVEL keys (instagram,
// tiktok…) — unlike /v1/linktree, the vendor carries both halves, so this
// normalizer emits tiles first and socials behind them, mirroring the order
// the Linktree lane achieves via its inline-parse union.
//
// Recorded-payload quirks absorbed here (2026-09-01, kimkardashian.komi.io):
// rows carry a `visible` flag and hidden modules RIDE ALONG in the payload
// (10 archived PRODUCT tiles on the recorded page) — the rendered page does
// not show them, so parity means dropping visible:false (and the PODCAST
// rows' `active:false` twin); module-group rows ship with no url at all;
// titles carry real trailing whitespace — trimmed. Nothing from the raw body
// is spread through, so credits_* can never reach persistence.
//
// Returns null unless the payload positively carries a usable page: a
// username AND at least one http(s) link. A NotFound husk, shape drift, or an
// all-hidden page must all read as "vendor miss" so the caller's fallback
// runs exactly as it does today.
class KomiLinksNormalizer
{
    /** The icon-row socials, as the vendor names them at top level. */
    private const SOCIAL_KEYS = ['instagram', 'tiktok', 'youtube', 'twitter', 'facebook', 'snapchat', 'website'];

    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{username: string, links: non-empty-list<array{url: string, title?: string, id?: int|string, type?: string, price?: int|float, currency?: string}>, avatar?: string, displayName?: string}|null
     */
    public function normalize(array $body): ?array
    {
        $username = $body['username'] ?? null;
        $links = $body['links'] ?? null;
        if (! is_string($username) || $username === '' || ! is_array($links)) {
            return null;
        }

        $rows = [];
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            // Hidden modules travel in the payload; the page never shows them.
            if (($link['visible'] ?? null) === false || ($link['active'] ?? null) === false) {
                continue;
            }

            $url = trim((string) ($link['url'] ?? ''));
            if (preg_match('~^https?://~i', $url) !== 1 || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $row = ['url' => $url];

            $title = trim((string) ($link['title'] ?? ''));
            if ($title !== '') {
                $row['title'] = $title;
            }

            $id = $link['id'] ?? null;
            if (is_int($id) || (is_string($id) && $id !== '')) {
                $row['id'] = $id;
            }

            $type = $link['type'] ?? null;
            if (is_string($type) && $type !== '') {
                $row['type'] = $type;
            }

            // PRODUCT tiles price their goods — kept for a future shop-pool
            // consumer; the unroll reads urls() only.
            if (is_int($link['price'] ?? null) || is_float($link['price'] ?? null)) {
                $row['price'] = $link['price'];
            }
            if (is_string($link['currency'] ?? null) && $link['currency'] !== '') {
                $row['currency'] = $link['currency'];
            }

            $rows[] = $row;
        }

        foreach (self::SOCIAL_KEYS as $key) {
            $url = $body[$key] ?? null;
            $url = is_string($url) ? trim($url) : '';
            if (preg_match('~^https?://~i', $url) !== 1 || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $rows[] = ['url' => $url];
        }

        if ($rows === []) {
            return null;
        }

        $page = ['username' => $username, 'links' => $rows];

        foreach (['avatar', 'displayName'] as $key) {
            if (is_string($body[$key] ?? null) && $body[$key] !== '') {
                $page[$key] = $body[$key];
            }
        }

        return $page;
    }

    /**
     * The unroll-facing view: destination URLs in page order, tiles leading
     * the icon-row socials — the shape LinkInBioImporter consumes.
     *
     * @param  array{links: non-empty-list<array{url: string}>}  $page  a normalize() result
     * @return non-empty-list<string>
     */
    public function urls(array $page): array
    {
        return array_column($page['links'], 'url');
    }
}
