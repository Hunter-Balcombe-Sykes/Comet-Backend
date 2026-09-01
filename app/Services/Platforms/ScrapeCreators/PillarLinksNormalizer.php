<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b: ScrapeCreators' /v1/pillar returns three link surfaces — links[]
// (the owner's tiles), products[] (their merch), and the icon-row socials as
// top-level keys where ABSENT means empty string, not omitted. All three are
// the owner's published URLs, so all three feed the unroll: tiles, then
// products, then socials, deduped by exact URL.
//
// Recorded-payload quirks absorbed here (2026-09-01, pillar.io/angelstrife):
// links[].type merely repeats the title (no discriminator lives there — it is
// deliberately NOT copied, so the row vocabulary the Linktree lane
// established stays honest); products[].price was 0 on every real row —
// dropped as meaningless. The payload also carries the owner's CONTACT PII
// (email, email_primary, location): nothing from the raw body is spread
// through, so none of it — nor credits_* — can ever reach persistence.
//
// Returns null unless the payload positively carries a usable page: the
// vendor's page id AND at least one http(s) link. A NotFound husk or shape
// drift must read as "vendor miss" so the caller's fallback runs unchanged.
class PillarLinksNormalizer
{
    /** The social keys the recorded payload ships; empty string means unset. */
    private const SOCIAL_KEYS = [
        'amazon', 'medium', 'tiktok', 'twitch', 'discord', 'patreon',
        'spotify', 'twitter', 'youtube', 'facebook', 'linkedin', 'snapchat',
        'instagram', 'soundcloud', 'apple_app_store', 'google_app_store',
    ];

    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{links: non-empty-list<array{url: string, title?: string, id?: string}>, name?: string}|null
     */
    public function normalize(array $body): ?array
    {
        $id = $body['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        $rows = [];
        $seen = [];

        foreach (['links', 'products'] as $surface) {
            $surfaceRows = $body[$surface] ?? null;
            if (! is_array($surfaceRows)) {
                continue;
            }

            foreach ($surfaceRows as $link) {
                if (! is_array($link)) {
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

                if (is_string($link['id'] ?? null) && $link['id'] !== '') {
                    $row['id'] = $link['id'];
                }

                $rows[] = $row;
            }
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

        $page = ['links' => $rows];

        // Pillar has no username field; the display name is the only identity
        // the payload offers.
        $name = trim(((string) ($body['first_name'] ?? '')).' '.((string) ($body['last_name'] ?? '')));
        if ($name !== '') {
            $page['name'] = $name;
        }

        return $page;
    }

    /**
     * The unroll-facing view: destination URLs in page order — tiles, then
     * products, then the icon-row socials.
     *
     * @param  array{links: non-empty-list<array{url: string}>}  $page  a normalize() result
     * @return non-empty-list<string>
     */
    public function urls(array $page): array
    {
        return array_column($page['links'], 'url');
    }
}
