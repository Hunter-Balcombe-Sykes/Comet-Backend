<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11c (2026-09-01): /v1/youtube/channel/shorts → the exact row shape
// YoutubeScraper's video lanes already speak (the RSS six + lengthSeconds,
// vendorUploadsFeed's contract), so a short is indistinguishable from a video
// to every watch-pool reader. Rows are SYNTHESIZED, never spread from the
// vendor item, so credits_* and the engagement/text-count fields can never
// leak into a persisted payload.
//
// Trial-verified quirks absorbed here (recorded payload 2026-09-01, MrBeast):
//  - `publishDate` is a REAL per-item ISO date on this endpoint — unlike the
//    lives endpoint there is no synthesized publishedTime trap to dodge.
//  - `description` is an explicit null (not omitted) on most shorts.
//  - `durationMs` is MILLISECONDS — same conversion the TikTok normalizer
//    makes, or every watch card claims a ten-hour short.
class YoutubeShortsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/youtube/channel/shorts page
     * @return list<array{videoId: string, name: string, description: string, link: string, date: ?string, thumbnail: string, lengthSeconds: ?int}>|null
     *                                                                                                                                                   null unless the page positively carries at least one id-bearing
     *                                                                                                                                                   short — a NotFound husk answers success:true with `shorts: []` and
     *                                                                                                                                                   must read as "vendor miss", never as a channel with no shorts.
     */
    public function rows(array $body): ?array
    {
        $list = $body['shorts'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                continue;
            }
            $id = $item['id'];

            $date = $item['publishDate'] ?? null;
            $durationMs = is_numeric($item['durationMs'] ?? null) ? (int) $item['durationMs'] : 0;

            $rows[] = [
                'videoId' => $id,
                'name' => is_string($item['title'] ?? null) ? $item['title'] : '',
                'description' => is_string($item['description'] ?? null) ? trim($item['description']) : '',
                'link' => "https://www.youtube.com/watch?v={$id}",
                'date' => is_string($date) && trim($date) !== '' ? $date : null,
                'thumbnail' => is_string($item['thumbnail'] ?? null) ? $item['thumbnail'] : '',
                'lengthSeconds' => $durationMs > 0 ? max(1, (int) round($durationMs / 1000)) : null,
            ];
        }

        return $rows === [] ? null : $rows;
    }
}
