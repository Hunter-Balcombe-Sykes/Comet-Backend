<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b (2026-09-01): /v1/bluesky/user-posts → the account's OWN authored
// posts, as media-candidate rows. No Apify lane behind this either — rows are
// synthesized (never spread), so credits_* and the vendor's per-item bulk can
// never leak toward persistence.
//
// Trial-verified quirks absorbed here (recorded payloads 2026-09-01):
//  - reposts arrive as FOREIGN-AUTHOR items with NO reason marker (the vendor
//    strips getAuthorFeed's wrapper — bsky.app's feed carried 6 foreign posts,
//    zero `reason` keys), so the author match against the caller's did/handle
//    IS the repost filter. Nothing another account posted may become this
//    user's media.
//  - replies ride the feed too, flagged only by record.reply — dropped, the
//    profile surface is authored top-level posts only.
//  - the pinned post leads the feed OUT of chronological order (bsky.app's
//    2024 pinned intro rode first among 2026 posts) — this class only maps;
//    the caller re-sorts by createdAt, the TikTok lane's exact split.
//  - video is an HLS playlist + thumbnail, no mp4 — the thumbnail is the
//    mirrorable frame; images ship fullsize + thumb as unsigned CDN URLs.
class BlueskyPostsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/bluesky/user-posts page
     * @param  string  $account  the requested account's did (preferred) or handle
     * @return non-empty-list<array<string, mixed>>|null null unless the page
     *                                                   positively carries at least one own authored post — a husk, a
     *                                                   reposts-only page, or shape drift must read as "vendor miss",
     *                                                   never as an empty account.
     */
    public function rows(array $body, string $account): ?array
    {
        $feed = $body['feed'] ?? null;
        if (! is_array($feed) || trim($account) === '') {
            return null;
        }
        $account = trim($account);

        $rows = [];
        foreach ($feed as $item) {
            if (! is_array($item)) {
                continue;
            }

            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            if (! $this->isOwn($author, $account)) {
                continue;
            }

            $record = is_array($item['record'] ?? null) ? $item['record'] : null;
            if ($record === null || isset($record['reply'])) {
                continue;
            }

            $uri = is_string($item['uri'] ?? null) ? $item['uri'] : '';
            if (preg_match('~^at://[^/]+/app\.bsky\.feed\.post/([^/]+)$~', $uri, $m) !== 1) {
                continue;
            }
            $rkey = $m[1];

            $handle = is_string($author['handle'] ?? null) && trim($author['handle']) !== ''
                ? trim($author['handle'])
                : $account;

            $embed = is_array($item['embed'] ?? null) ? $item['embed'] : [];
            $video = $this->video($embed);

            $text = is_string($record['text'] ?? null) && trim($record['text']) !== '' ? trim($record['text']) : null;

            $rows[] = [
                'id' => $rkey,
                'uri' => $uri,
                'url' => 'https://bsky.app/profile/'.$handle.'/post/'.$rkey,
                'text' => $text,
                'createdAt' => is_string($record['createdAt'] ?? null) ? $record['createdAt'] : null,
                'isVideo' => $video !== null,
                'images' => $this->images($embed),
                'video' => $video,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /** @param array<string, mixed> $author */
    private function isOwn(array $author, string $account): bool
    {
        if (str_starts_with($account, 'did:')) {
            return ($author['did'] ?? null) === $account;
        }

        $handle = $author['handle'] ?? null;

        return is_string($handle) && strcasecmp(trim($handle), ltrim($account, '@')) === 0;
    }

    /**
     * @param  array<string, mixed>  $embed
     * @return list<array<string, mixed>>
     */
    private function images(array $embed): array
    {
        if (($embed['$type'] ?? null) !== 'app.bsky.embed.images#view' || ! is_array($embed['images'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($embed['images'] as $image) {
            if (! is_array($image)) {
                continue;
            }
            $fullsize = $image['fullsize'] ?? null;
            if (! is_string($fullsize) || preg_match('~^https?://~i', $fullsize) !== 1) {
                continue;
            }
            $out[] = array_filter([
                'url' => $fullsize,
                'thumb' => is_string($image['thumb'] ?? null) ? $image['thumb'] : null,
                'alt' => is_string($image['alt'] ?? null) && trim($image['alt']) !== '' ? trim($image['alt']) : null,
                'width' => $this->dimension($image, 'width'),
                'height' => $this->dimension($image, 'height'),
            ], static fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>|null
     */
    private function video(array $embed): ?array
    {
        if (($embed['$type'] ?? null) !== 'app.bsky.embed.video#view') {
            return null;
        }

        $playlist = $embed['playlist'] ?? null;
        if (! is_string($playlist) || preg_match('~^https?://~i', $playlist) !== 1) {
            return null;
        }

        return array_filter([
            'playlist' => $playlist,
            'thumbnail' => is_string($embed['thumbnail'] ?? null) ? $embed['thumbnail'] : null,
            'alt' => is_string($embed['alt'] ?? null) && trim($embed['alt']) !== '' ? trim($embed['alt']) : null,
            'width' => $this->dimension($embed, 'width'),
            'height' => $this->dimension($embed, 'height'),
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $carrier */
    private function dimension(array $carrier, string $side): ?int
    {
        $ratio = is_array($carrier['aspectRatio'] ?? null) ? $carrier['aspectRatio'] : [];
        $value = $ratio[$side] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
