<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11b (2026-09-01): /v1/instagram/user/reels → media-stream rows in the
// LANDED vocabulary (the exact shape InstagramConnector::mapPost() emits), so
// a blended depth reel is indistinguishable from a window post to the
// projector. Rows are SYNTHESIZED, never spread: the vendor item is
// Instagram's private-API v1 media (~90 keys under items[].media) and none of
// it may leak into a persisted payload.
//
// This endpoint speaks a DIFFERENT dialect from /v1/instagram/profile's
// GraphQL user (which is why this normalizer exists at all): `code` instead
// of `shortcode`, `taken_at` epoch instead of `taken_at_timestamp`,
// `image_versions2.candidates` instead of `display_url`, `video_versions`
// instead of `video_url`. Recorded-payload notes (ryanfitzsimonshair capture,
// 2026-09-01): every row is media_type 2 / product_type "clips" with a
// caption dict (the vendor docs claim reels carry no captions — this account
// disproves that, so caption.text is read but stays optional).
//
// taken_at is re-expressed as the connector's own gmdate ISO format — the
// stream orders by strcmp over `taken_at`, so one stream must not carry two
// clocks (the YoutubeRss shorts-blend lesson). created_at is IGNORED: its
// `.000Z` millisecond suffix is exactly the second clock.
class InstagramReelsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/instagram/user/reels page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one playable reel — a husk must read as
     *                                         "vendor miss", never as an account with no video history.
     */
    public function rows(array $body): ?array
    {
        $items = $body['items'] ?? null;
        if (! is_array($items)) {
            return null;
        }

        $rows = [];
        foreach ($items as $item) {
            $media = is_array($item) ? ($item['media'] ?? null) : null;
            if (! is_array($media)) {
                continue;
            }

            $code = is_string($media['code'] ?? null) ? trim($media['code']) : '';
            $video = self::bestVideoUrl($media['video_versions'] ?? null);
            if ($code === '' || $video === null) {
                // A reel without an mp4 cannot fill the video surface this
                // lane exists for (plan 11b) — skip rather than land a husk.
                continue;
            }

            $cover = $this->firstUrl(
                is_array($media['image_versions2'] ?? null) ? ($media['image_versions2']['candidates'] ?? null) : null
            );
            $takenAt = is_numeric($media['taken_at'] ?? null) ? (int) $media['taken_at'] : null;
            $caption = is_array($media['caption'] ?? null) ? ($media['caption']['text'] ?? null) : null;
            $caption = is_string($caption) && trim($caption) !== '' ? trim($caption) : null;

            $rows[] = array_filter([
                'shortcode' => $code,
                'type' => 'Video',
                'caption' => $caption,
                'taken_at' => $takenAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $takenAt),
                'url' => 'https://www.instagram.com/reel/'.$code.'/',
                'display_url' => $cover,
                'video_url' => $video,
                'images' => $cover === null ? null : [$cover],
            ], static fn ($v) => $v !== null);
        }

        return $rows === [] ? null : $rows;
    }

    /** @param mixed $versions the first entry's url, from a candidates/versions list */
    /**
     * The best rendition (2026-09-02): highest width, then bandwidth — the
     * vendor lists several (1276/720/480 on the recorded capture) and `[0]`
     * is not reliably the best. Any url-bearing entry beats none.
     */
    public static function bestVideoUrl(mixed $versions): ?string
    {
        if (! is_array($versions)) {
            return null;
        }
        $best = null;
        $bestScore = -1;
        foreach ($versions as $v) {
            if (! is_array($v)) {
                continue;
            }
            $url = $v['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            $score = ((int) ($v['width'] ?? 0)) * 100000 + (int) ($v['bandwidth'] ?? 0);
            if ($best === null || $score > $bestScore) {
                $best = $url;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function firstUrl(mixed $versions): ?string
    {
        $first = is_array($versions) ? ($versions[0] ?? null) : null;
        $url = is_array($first) ? ($first['url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
