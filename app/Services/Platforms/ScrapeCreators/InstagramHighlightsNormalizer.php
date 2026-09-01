<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11b (2026-09-01): /v1/instagram/user/highlights → media-stream rows
// (owner-curated sets as gallery candidates). The listing endpoint returns
// GraphHighlightReel albums — id, title, cover — NOT the story items inside
// them; walking /v1/instagram/user/highlight/detail would bill one credit per
// set for ephemeral-grade story frames, so the cover IS the candidate: it is
// the one image the owner chose to front the set.
//
// The row identity is a SYNTHESIZED `highlight-{id}` shortcode. The media
// stream requires a shortcode and highlights have none — the prefix keeps the
// key stable across runs and unmistakably out of the real-shortcode
// namespace, so a depth dedupe against window posts can never collide.
// Highlights carry NO dates; rows stay dateless (`taken_at` absent), which
// under the stream's prefix coverage means they land below the claim and can
// never be wrongly deleted.
class InstagramHighlightsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full /v1/instagram/user/highlights answer
     * @return list<array<string, mixed>>|null null unless the answer positively
     *                                         carries at least one covered highlight — a husk must read
     *                                         as "vendor miss", never as an account with no highlights.
     */
    public function rows(array $body): ?array
    {
        $list = $body['highlights'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = is_string($item['id'] ?? null) ? trim($item['id']) : '';
            $cover = $this->cover($item);
            if (preg_match('/^\d+$/', $id) !== 1 || $cover === null) {
                continue;
            }

            $title = is_string($item['title'] ?? null) && trim($item['title']) !== '' ? trim($item['title']) : null;

            $rows[] = array_filter([
                'shortcode' => 'highlight-'.$id,
                'type' => 'Highlight',
                'caption' => $title,
                'url' => 'https://www.instagram.com/stories/highlights/'.$id.'/',
                'display_url' => $cover,
                'images' => [$cover],
            ], static fn ($v) => $v !== null);
        }

        return $rows === [] ? null : $rows;
    }

    /** @param array<string, mixed> $item largest rendition first — the cropped 150px square is the fallback */
    private function cover(array $item): ?string
    {
        $full = is_array($item['cover_media'] ?? null) ? ($item['cover_media']['thumbnail_src'] ?? null) : null;
        if (is_string($full) && $full !== '') {
            return $full;
        }

        $cropped = is_array($item['cover_media_cropped_thumbnail'] ?? null)
            ? ($item['cover_media_cropped_thumbnail']['url'] ?? null)
            : null;

        return is_string($cropped) && $cropped !== '' ? $cropped : null;
    }
}
