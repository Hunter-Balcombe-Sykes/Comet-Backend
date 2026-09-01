<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11b (2026-09-01): /v1/instagram/user/tagged-posts → media-stream rows
// (social-proof imagery: posts OTHER accounts published with this account
// tagged). Rows are SYNTHESIZED in the landed vocabulary, never spread.
//
// Recorded-payload quirks this class absorbs (ryanfitzsimonshair capture,
// 2026-09-01):
//  - posts are flat XDTMediaDict objects speaking `code` + `display_uri`
//    (URI, not url) + `image_versions2` — a third dialect next to the
//    profile's GraphQL nodes and the reels endpoint's v1 media;
//  - NO date field exists anywhere in the payload (taken_at count: zero),
//    so rows are honestly dateless and land below the prefix claim;
//  - a media_type 2 post carries NO video_versions — tagged videos arrive as
//    poster imagery only, which is exactly the "social-proof imagery" this
//    lane wants (video-ness downstream rides on video_url presence, so these
//    project as plain images);
//  - a carousel (media_type 8) nests carousel_media children whose frames
//    live in image_versions2.candidates with no display_uri of their own.
//
// The tagger's identity (posts[].user — a THIRD PARTY, not the connected
// account) is deliberately never mapped: landing another person's username
// in a user's payload is a PII decision nobody made.
class InstagramTaggedPostsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/instagram/user/tagged-posts page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one image-bearing post — a husk must read
     *                                         as "vendor miss", never as an account nobody tags.
     */
    public function rows(array $body): ?array
    {
        $list = $body['posts'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $post) {
            if (! is_array($post)) {
                continue;
            }
            $code = is_string($post['code'] ?? null) ? trim($post['code']) : '';
            $cover = $this->image($post);
            if ($code === '' || $cover === null) {
                continue;
            }

            $images = $this->carouselFrames($post);
            $caption = is_array($post['caption'] ?? null) ? ($post['caption']['text'] ?? null) : null;
            $caption = is_string($caption) && trim($caption) !== '' ? trim($caption) : null;
            $url = is_string($post['url'] ?? null) && trim($post['url']) !== ''
                ? trim($post['url'])
                : 'https://www.instagram.com/p/'.$code.'/';

            $rows[] = array_filter([
                'shortcode' => $code,
                'type' => match ((int) ($post['media_type'] ?? 0)) {
                    2 => 'Video',
                    8 => 'Sidecar',
                    default => 'Image',
                },
                'caption' => $caption,
                'url' => $url,
                'display_url' => $cover,
                'images' => $images === [] ? [$cover] : $images,
            ], static fn ($v) => $v !== null);
        }

        return $rows === [] ? null : $rows;
    }

    /** @param array<string, mixed> $post display_uri first, largest image_versions2 candidate as fallback */
    private function image(array $post): ?string
    {
        $uri = $post['display_uri'] ?? null;
        if (is_string($uri) && $uri !== '') {
            return $uri;
        }

        return $this->candidateUrl($post);
    }

    /**
     * A carousel is ONE record carrying every readable child frame in order —
     * the same one-item-N-gallery-rows contract the window's Sidecar mapping
     * keeps (plan §6).
     *
     * @param  array<string, mixed>  $post
     * @return list<string>
     */
    private function carouselFrames(array $post): array
    {
        $children = $post['carousel_media'] ?? null;
        if (! is_array($children)) {
            return [];
        }

        $frames = [];
        foreach ($children as $child) {
            $url = is_array($child) ? $this->candidateUrl($child) : null;
            if ($url !== null) {
                $frames[] = $url;
            }
        }

        return $frames;
    }

    /** @param array<string, mixed> $media image_versions2.candidates[0].url — the largest rendition */
    private function candidateUrl(array $media): ?string
    {
        $candidates = is_array($media['image_versions2'] ?? null)
            ? ($media['image_versions2']['candidates'] ?? null)
            : null;
        $first = is_array($candidates) ? ($candidates[0] ?? null) : null;
        $url = is_array($first) ? ($first['url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
