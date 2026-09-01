<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10a (2026-09-01): /v1/twitch/user/videos → watch-pool rows in the
// vocabulary the watch lane's existing sources already speak (YoutubeFeed /
// VimeoConnector items: id/title/url/published/thumbnail + enrichment).
// Rows are SYNTHESIZED, never spread from the vendor item — the payload is
// Twitch GraphQL (__typename on every object, owner/self/broadcastIdentifier
// blocks, credits_* billing fields) and none of it may leak into a persisted
// record.
//
// Trial-verified quirks absorbed here (recorded payload 2026-09-01, jynxzi):
//  - the VOD of a stream that is LIVE RIGHT NOW already appears, with
//    previewThumbnailURL pointing at vod-secure.twitch.tv/_404/
//    404_processing_…png — a placeholder that literally 404-renders. Mapped
//    to thumbnail: null so no card ever serves it.
//  - lengthSeconds is SECONDS (no TikTok-style ms conversion), and grows on
//    that in-progress row across calls; viewCount grows on every row. Both
//    are volatile — the connector must declare them so, or every pull reads
//    as a content change.
//  - game is null on some rows (observed live on an ishowspeed VOD).
//  - the endpoint answers newest-first under sort_by=TIME, but the caller
//    re-sorts anyway — ordering is a contract, not an observation.
class TwitchVideosNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/twitch/user/videos page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one id-bearing video — a husk (NotFound bills a
     *                                         credit as success:true) must read as "vendor miss", never as an
     *                                         empty channel.
     */
    public function rows(array $body): ?array
    {
        $list = $body['videos'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $title = is_string($item['title'] ?? null) ? trim($item['title']) : '';
            if (preg_match('/^\d+$/', $id) !== 1 || $title === '') {
                // Un-renderable and un-linkable — the watch stream requires
                // id/title/url, so a row missing either is dropped, not landed.
                continue;
            }

            $game = is_array($item['game'] ?? null) ? $item['game'] : [];

            $rows[] = [
                'id' => $id,
                'title' => $title,
                'url' => 'https://www.twitch.tv/videos/'.$id,
                'published' => is_string($item['publishedAt'] ?? null) ? $item['publishedAt'] : null,
                'thumbnail' => $this->thumbnail($item['previewThumbnailURL'] ?? null),
                'duration' => is_numeric($item['lengthSeconds'] ?? null) && (int) $item['lengthSeconds'] > 0
                    ? (int) $item['lengthSeconds']
                    : null,
                'views' => is_numeric($item['viewCount'] ?? null) ? (int) $item['viewCount'] : null,
                'game' => is_string($game['displayName'] ?? null) && $game['displayName'] !== ''
                    ? $game['displayName']
                    : null,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    private function thumbnail(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('~^https?://~i', $value) !== 1) {
            return null;
        }

        // The still-processing placeholder (see class docblock) serves a 404.
        return str_contains($value, '404_processing') ? null : $value;
    }
}
