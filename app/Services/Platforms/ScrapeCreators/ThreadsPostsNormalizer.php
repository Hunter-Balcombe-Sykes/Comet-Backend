<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10a (2026-09-01): /v1/threads/user/posts → the last ~20-30 public
// posts, as rows for the future ThreadsConnector's `media` stream (the
// Instagram media-pool lane is the precedent: one row per post, a carousel
// is ONE row whose gallery frames are the children in order).
//
// Rows are SYNTHESIZED, never spread from the vendor post — credits_* and
// the ~30 vendor-only keys per post can never leak into a persisted payload.
//
// MIRROR-REQUIRED marking: every asset URL here is IG-signed and expiring
// (scontent-*.cdninstagram.com, oe/_nc_* signatures) — hot-linking is
// forbidden by the owned-bytes doctrine. Each media entry therefore carries
// its stable ref in the owned `threads:` namespace (threads:{pk}:{i} /
// threads:{pk}:video), the exact vocabulary InstagramMediaProjector mints
// for the media pool: MediaMirror::isOwnedEntry() reads the ref prefix, and
// the wiring pass must add 'threads:' to MediaMirror::OWNED_REF_PREFIXES or
// every frame stays unmirrored (fails safe: unlisted refs never hot-mirror,
// and an unmirrored video never renders).
//
// Trial-verified shapes absorbed here (recorded fixture 2026-09-01, mosseri):
//  - image_versions2.candidates is best-first — candidates[0] is the frame.
//  - media_type is an enum (19 text / 1 image / 2 video / 8 carousel) but
//    rows gate on SHAPE (video_versions / carousel_media presence), never
//    the enum.
//  - a MIXED carousel exists (children carrying video_versions): children
//    contribute poster frames only, like Instagram's sidecar mapping — the
//    only `video` entry a row can carry is the post's own top-level mp4.
//  - text-only posts keep their row with media:[] — whether a textual
//    thread enters the media pool is the projector's call, not this class's.
//
// This class only maps. The consumer re-sorts by taken_at before serving
// (pinned_post_info exists on the wire, so pinned-first ordering is
// possible), exactly as the Instagram/TikTok connectors do.
class ThreadsPostsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one usable post — a husk must read as
     *                                         "vendor miss", never as an empty account.
     */
    public function rows(array $body): ?array
    {
        $posts = $body['posts'] ?? null;
        if (! is_array($posts)) {
            return null;
        }

        $rows = [];
        foreach ($posts as $post) {
            $row = is_array($post) ? $this->mapPost($post) : null;
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows === [] ? null : $rows;
    }

    /** @param array<string, mixed> $post */
    private function mapPost(array $post): ?array
    {
        $pk = trim((string) ($post['pk'] ?? ''));
        if (preg_match('/^\d+$/', $pk) !== 1) {
            return null;
        }

        $info = is_array($post['text_post_app_info'] ?? null) ? $post['text_post_app_info'] : [];
        // The profile feed can carry replies — a reply is conversation, not
        // the account's own surface, so it never becomes a feed row.
        if (($info['is_reply'] ?? null) === true) {
            return null;
        }

        $code = is_string($post['code'] ?? null) && trim($post['code']) !== '' ? trim($post['code']) : null;
        $caption = is_array($post['caption'] ?? null) ? ($post['caption']['text'] ?? null) : null;
        $takenAt = is_numeric($post['taken_at'] ?? null) ? (int) $post['taken_at'] : 0;
        $video = $this->firstUrl($post['video_versions'] ?? null);

        return array_filter([
            'id' => $pk,
            'code' => $code,
            'caption' => is_string($caption) && trim($caption) !== '' ? trim($caption) : null,
            'taken_at' => $takenAt > 0 ? gmdate('Y-m-d\TH:i:s\Z', $takenAt) : null,
            'url' => $this->postUrl($post, $code),
            'is_video' => $video !== null,
            'like_count' => $this->count($post, 'like_count'),
            'reply_count' => $this->count($info, 'direct_reply_count'),
            'repost_count' => $this->count($info, 'repost_count'),
            'media' => $this->media($post, $pk, $video),
        ], static fn ($v) => $v !== null);
    }

    /**
     * The row's media entries in projector vocabulary: cover first, gallery
     * frames in order, the post's own mp4 last — each under its owned
     * `threads:` ref so the mirror lane recognises the bytes as ours to hold.
     *
     * @param  array<string, mixed>  $post
     * @return list<array{role: string, url: string, ref: string}>
     */
    private function media(array $post, string $pk, ?string $video): array
    {
        // A carousel's children ARE the frames (the top-level candidates just
        // duplicate child 0's cover); everything else has at most one frame.
        $frames = [];
        if (is_array($post['carousel_media'] ?? null)) {
            foreach ($post['carousel_media'] as $child) {
                $frame = is_array($child) ? $this->coverUrl($child) : null;
                if ($frame !== null) {
                    $frames[] = $frame;
                }
            }
        }
        if ($frames === []) {
            $cover = $this->coverUrl($post);
            if ($cover !== null) {
                $frames = [$cover];
            }
        }

        $media = [];
        foreach ($frames as $i => $url) {
            $media[] = [
                'role' => $i === 0 ? 'cover' : 'gallery',
                'url' => $url,
                // Stable across CDN re-signing: the frame's place in the post.
                'ref' => "threads:{$pk}:{$i}",
            ];
        }
        if ($video !== null) {
            $media[] = [
                'role' => 'video',
                'url' => $video,
                'ref' => "threads:{$pk}:video",
            ];
        }

        return $media;
    }

    /** @param array<string, mixed> $item a post or carousel child */
    private function coverUrl(array $item): ?string
    {
        $versions = is_array($item['image_versions2'] ?? null) ? $item['image_versions2'] : [];
        $candidates = is_array($versions['candidates'] ?? null) ? $versions['candidates'] : [];
        $first = $candidates[0] ?? null;
        $url = is_array($first) ? ($first['url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @param array<string, mixed>|mixed $versions */
    private function firstUrl(mixed $versions): ?string
    {
        $first = is_array($versions) ? ($versions[0] ?? null) : null;
        $url = is_array($first) ? ($first['url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @param array<string, mixed> $post */
    private function postUrl(array $post, ?string $code): ?string
    {
        $url = $post['url'] ?? null;
        if (is_string($url) && trim($url) !== '') {
            return trim($url);
        }

        $user = is_array($post['user'] ?? null) ? $post['user'] : [];
        $username = is_string($user['username'] ?? null) ? trim($user['username']) : '';

        return $username !== '' && $code !== null
            ? 'https://www.threads.com/@'.$username.'/post/'.$code
            : null;
    }

    /** @param array<string, mixed> $source */
    private function count(array $source, string $key): ?int
    {
        return is_numeric($source[$key] ?? null) ? (int) $source[$key] : null;
    }
}
