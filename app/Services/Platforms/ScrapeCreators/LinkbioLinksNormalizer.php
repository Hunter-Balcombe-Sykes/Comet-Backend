<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b: ScrapeCreators' /v1/linkbio reads Lnk.Bio — the ONE quartet host
// that matters most, because lnk.bio itself answers 403 to both of
// SafeUrlFetcher's UAs (the Cloudflare class LinkInBioImporter's zero-yield
// floor documents), so the vendor is not a faster parser here but the only
// reader we have. Rows are {url, text}; the icon-row socials are top-level
// keys where absent means NULL (a third vocabulary — Komi omits nothing,
// Pillar ships empty strings). text maps to the family's `title`.
//
// The payload also carries a contact email key: nothing from the raw body is
// spread through, so neither it nor credits_* can ever reach persistence.
//
// Returns null unless the payload positively carries a usable page: a handle
// AND at least one http(s) link. A NotFound husk or shape drift must read as
// "vendor miss" so the caller's fallback accounting runs unchanged.
class LinkbioLinksNormalizer
{
    /** The social keys the recorded payload ships; null means unset. */
    private const SOCIAL_KEYS = ['instagram', 'tiktok', 'youtube', 'twitter', 'whatsapp', 'facebook', 'website'];

    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{username: string, links: non-empty-list<array{url: string, title?: string}>}|null
     */
    public function normalize(array $body): ?array
    {
        $handle = $body['handle'] ?? null;
        $links = $body['links'] ?? null;
        if (! is_string($handle) || $handle === '' || ! is_array($links)) {
            return null;
        }

        $rows = [];
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $url = trim((string) ($link['url'] ?? ''));
            if (preg_match('~^https?://~i', $url) !== 1 || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $row = ['url' => $url];

            $title = trim((string) ($link['text'] ?? ''));
            if ($title !== '') {
                $row['title'] = $title;
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

        return ['username' => $handle, 'links' => $rows];
    }

    /**
     * The unroll-facing view: destination URLs in page order.
     *
     * @param  array{links: non-empty-list<array{url: string}>}  $page  a normalize() result
     * @return non-empty-list<string>
     */
    public function urls(array $page): array
    {
        return array_column($page['links'], 'url');
    }
}
