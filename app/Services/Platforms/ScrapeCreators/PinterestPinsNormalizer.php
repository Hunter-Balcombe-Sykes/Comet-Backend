<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10a (2026-09-01): /v1/pinterest/board → media-pool candidate rows.
// Rows are SYNTHESIZED, never spread from the vendor item, so credits_* and
// the ~80 vendor-only keys per pin can never leak into a persisted payload.
//
// Recorded-payload quirks this class absorbs (food52 capture, 2026-09-01):
//  - the pins list carries NON-PIN rows (type:"story" annotation modules with
//    an alphanumeric id and no images) — the type+id+image gate drops them;
//  - `created_at` is null on every board pin: pins carry NO usable date, the
//    list order is the board's curation order, and nothing here may
//    synthesize a timestamp;
//  - `description` is often literal " " — whitespace collapses to null;
//  - a video pin can carry is_video:false while videos.video_list holds the
//    mp4 — video is read from SHAPE (V_720P.url), never from the flag;
//  - video_list durations are MILLISECONDS (92933 → 93s), the same ms quirk
//    the TikTok normalizer converts.
class PinterestPinsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/pinterest/board page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one image-bearing pin — a husk must read
     *                                         as "vendor miss", never as an empty board.
     */
    public function rows(array $body): ?array
    {
        $list = $body['pins'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'pin') {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $image = $this->image($item);
            if (preg_match('/^\d+$/', $id) !== 1 || $image === null) {
                continue;
            }

            $video = $this->video($item);
            $board = is_array($item['board'] ?? null) ? $item['board'] : [];
            $boardId = trim((string) ($board['id'] ?? ''));

            $rows[] = [
                'id' => $id,
                'title' => $this->text($item['grid_title'] ?? null) ?? $this->text($item['title'] ?? null),
                'caption' => $this->text($item['description'] ?? null),
                'url' => 'https://www.pinterest.com/pin/'.$id.'/',
                'image' => $image,
                'video_url' => $video['url'] ?? null,
                'duration' => $video['duration'] ?? null,
                'board_id' => preg_match('/^\d+$/', $boardId) === 1 ? $boardId : null,
                'board_name' => $this->text($board['name'] ?? null),
            ];
        }

        return $rows === [] ? null : $rows;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $item largest stable pinimg rendition — orig first */
    private function image(array $item): ?string
    {
        $images = is_array($item['images'] ?? null) ? $item['images'] : [];
        foreach (['orig', '736x', '474x', '236x'] as $size) {
            $url = is_array($images[$size] ?? null) ? ($images[$size]['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * The V_720P mp4 only — the HLS renditions are playlists, not bytes a
     * mirror can own.
     *
     * @param  array<string, mixed>  $item
     * @return array{url: string, duration: int|null}|null
     */
    private function video(array $item): ?array
    {
        $variant = is_array($item['videos'] ?? null)
            ? (is_array($item['videos']['video_list'] ?? null) ? ($item['videos']['video_list']['V_720P'] ?? null) : null)
            : null;
        $url = is_array($variant) ? ($variant['url'] ?? null) : null;
        if (! is_string($url) || ! str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '.mp4')) {
            return null;
        }

        $durationMs = is_numeric($variant['duration'] ?? null) ? (int) $variant['duration'] : 0;

        return [
            'url' => $url,
            'duration' => $durationMs > 0 ? max(1, (int) round($durationMs / 1000)) : null,
        ];
    }
}
