<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 8 G3 (2026-09-01): /v3/tiktok/profile/videos → the exact row shape the
// clockworks~tiktok-profile-scraper dataset taught TiktokConnector::mapVideo
// to read: id / text / createTimeISO / webVideoUrl / videoMeta{coverUrl,
// duration} / isPinned. Rows are SYNTHESIZED, never spread from the vendor
// item, so credits_* and the ~150 vendor-only keys per aweme can never leak
// into a persisted payload.
//
// Trial-verified quirks absorbed here (recorded payload 2026-09-01):
//  - `video.duration` is MILLISECONDS; the actor's videoMeta.duration is
//    seconds — convert, or every watch card claims a nine-hour video.
//  - pinned videos (`is_top` = 1) lead aweme_list out of order; this class
//    only maps — SocialActorDriver re-sorts after pagination.
//  - the /v1 profile's itemList is unreliable for content (G3) — content is
//    only ever read from this videos endpoint's aweme_list.
class TiktokVideosNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v3/tiktok/profile/videos page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one id-bearing video — a husk must read as
     *                                         "vendor miss", never as an empty account.
     */
    public function rows(array $body, string $username): ?array
    {
        $list = $body['aweme_list'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['aweme_id'] ?? ''));
            if (preg_match('/^\d+$/', $id) !== 1) {
                continue;
            }

            $video = is_array($item['video'] ?? null) ? $item['video'] : [];
            $durationMs = is_numeric($video['duration'] ?? null) ? (int) $video['duration'] : 0;
            $created = is_numeric($item['create_time'] ?? null) ? (int) $item['create_time'] : 0;

            $rows[] = [
                'id' => $id,
                'text' => is_string($item['desc'] ?? null) ? $item['desc'] : null,
                'createTimeISO' => $created > 0 ? gmdate('Y-m-d\TH:i:s.000\Z', $created) : null,
                'webVideoUrl' => 'https://www.tiktok.com/@'.$username.'/video/'.$id,
                'videoMeta' => array_filter([
                    'coverUrl' => $this->coverUrl($video),
                    'duration' => $durationMs > 0 ? max(1, (int) round($durationMs / 1000)) : null,
                ], static fn ($v) => $v !== null),
                'isPinned' => ($item['is_top'] ?? null) === 1,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /** @param array<string, mixed> $video */
    private function coverUrl(array $video): ?string
    {
        foreach (['cover', 'origin_cover'] as $key) {
            $urls = is_array($video[$key] ?? null) ? ($video[$key]['url_list'] ?? null) : null;
            $first = is_array($urls) ? ($urls[0] ?? null) : null;
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return null;
    }
}
