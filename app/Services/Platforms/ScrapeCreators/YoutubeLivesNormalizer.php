<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11c (2026-09-01): /v1/youtube/channel/lives → a LIVE-STATUS input,
// never watch-pool content. The top-level `isLive` bool is the normalized
// read Item 11d's CheckStreamingLiveStatusJob consolidation consumes; the
// rows exist so the consolidation can also name WHICH stream is live.
//
// Trial-verified quirks absorbed here (recorded payload 2026-09-01, LofiGirl
// — a channel with several concurrent live streams AND finished ones):
//  - a currently-live entry reads `lengthText: "LIVE"` with viewCountText
//    "N watching"; a finished stream carries "Streamed … ago" + "N views".
//    Either live marker is accepted — one surviving a vendor tweak keeps the
//    read alive.
//  - `viewCountInt` is BROKEN for abbreviated live counts ("1.4K watching"
//    arrives as the float 1.4, "14K watching" as 14) — the watcher count is
//    re-parsed from viewCountText, never read from the int.
//  - `publishedTime` is SYNTHESIZED at scrape time from the relative text
//    (every finished stream in the capture shares one identical timestamp —
//    the same trap as channel-videos' publishedTime), and lives carry no
//    publishDate at all, so rows deliberately have NO date field.
class YoutubeLivesNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/youtube/channel/lives page
     * @return array{isLive: bool, lives: list<array{videoId: string, name: string, link: string, thumbnail: ?string, isLive: bool, watching: ?int, lengthSeconds: ?int}>}|null
     *                                                                                                                                                                          null unless the page positively carries at least one id-bearing
     *                                                                                                                                                                          entry — a NotFound husk answers success:true with `lives: []` and
     *                                                                                                                                                                          must read as "vendor miss", never as "offline". isLive: false is
     *                                                                                                                                                                          only ever asserted off a populated Live tab with nothing live.
     */
    public function normalize(array $body): ?array
    {
        $list = $body['lives'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                continue;
            }
            $id = $item['id'];
            $isLive = $this->readsLive($item);

            $rows[] = [
                'videoId' => $id,
                'name' => is_string($item['title'] ?? null) ? $item['title'] : '',
                'link' => "https://www.youtube.com/watch?v={$id}",
                'thumbnail' => is_string($item['thumbnail'] ?? null) && $item['thumbnail'] !== ''
                    ? $item['thumbnail']
                    : null,
                'isLive' => $isLive,
                'watching' => $isLive ? $this->watching($item['viewCountText'] ?? null) : null,
                'lengthSeconds' => is_numeric($item['lengthSeconds'] ?? null) && (int) $item['lengthSeconds'] > 0
                    ? (int) $item['lengthSeconds']
                    : null,
            ];
        }

        if ($rows === []) {
            return null;
        }

        return [
            'isLive' => in_array(true, array_column($rows, 'isLive'), true),
            'lives' => $rows,
        ];
    }

    /** @param array<string, mixed> $item */
    private function readsLive(array $item): bool
    {
        return ($item['lengthText'] ?? null) === 'LIVE'
            || (is_string($item['viewCountText'] ?? null) && str_contains($item['viewCountText'], 'watching'));
    }

    private function watching(mixed $text): ?int
    {
        if (! is_string($text) || preg_match('/^([\d.,]+)\s*([KM])?\s*watching/i', trim($text), $m) !== 1) {
            return null;
        }

        $count = (int) round((float) str_replace(',', '', $m[1]) * match (strtoupper($m[2] ?? '')) {
            'K' => 1_000,
            'M' => 1_000_000,
            default => 1,
        });

        return $count > 0 ? $count : null;
    }
}
