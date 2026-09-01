<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 8 G3 (2026-09-01): /v1/facebook/profile/posts → the exact row shape
// the apify~facebook-posts-scraper dataset taught FacebookConnector::mapPost
// to read: postId / url / time / text / media[] ({__typename, photo_image.uri
// | thumbnail}) / isVideo. Rows are SYNTHESIZED, never spread, so credits_*
// and vendor-only keys can never leak into a persisted payload.
//
// Trial-verified shape notes (recorded payload 2026-09-01): `creation_time`
// is already the ISO string the connector strcmp-sorts on (publishTime is the
// unix fallback); a multi-photo post carries `images[]`, a single-image post
// sometimes only `image`; a reel carries `videoDetails` and its `image(s)` is
// the thumbnail. Text-only posts still land as rows (postId is the proof key)
// — the connector drops imageless posts itself, exactly as it does on the
// actor lane.
class FacebookPostsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/facebook/profile/posts page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one postId-bearing post — a husk must read
     *                                         as "vendor miss", never as an empty page.
     */
    public function rows(array $body): ?array
    {
        $posts = $body['posts'] ?? null;
        if (! is_array($posts)) {
            return null;
        }

        $rows = [];
        foreach ($posts as $post) {
            if (! is_array($post)) {
                continue;
            }
            $postId = trim((string) ($post['id'] ?? ''));
            if ($postId === '') {
                continue;
            }

            $isVideo = ! empty($post['videoDetails']);

            $imageUrls = [];
            foreach ((array) ($post['images'] ?? []) as $url) {
                if (is_string($url) && $url !== '') {
                    $imageUrls[] = $url;
                }
            }
            if ($imageUrls === [] && is_string($post['image'] ?? null) && $post['image'] !== '') {
                $imageUrls = [$post['image']];
            }

            $media = [];
            foreach ($imageUrls as $url) {
                $media[] = $isVideo
                    ? ['__typename' => 'Video', 'thumbnail' => $url]
                    : ['__typename' => 'Photo', 'photo_image' => ['uri' => $url]];
            }

            $url = $post['url'] ?? null;
            if (! is_string($url) || $url === '') {
                $url = is_string($post['permalink'] ?? null) ? $post['permalink'] : null;
            }

            $rows[] = [
                'postId' => $postId,
                'url' => $url,
                'time' => $this->time($post),
                'text' => is_string($post['text'] ?? null) ? $post['text'] : null,
                'media' => $media,
                'isVideo' => $isVideo,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /** @param array<string, mixed> $post */
    private function time(array $post): ?string
    {
        $iso = $post['creation_time'] ?? null;
        if (is_string($iso) && $iso !== '') {
            return $iso;
        }

        $ts = $post['publishTime'] ?? null;

        return is_numeric($ts) && (int) $ts > 0 ? gmdate('Y-m-d\TH:i:s.000\Z', (int) $ts) : null;
    }
}
