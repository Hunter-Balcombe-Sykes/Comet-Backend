<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11d (2026-09-01): /v1/tiktok/user/live → a LIVE-STATUS input for the
// CheckStreamingLiveStatusJob consolidation — never pool content. The vendor's
// own guidance is followed: the TOP-LEVEL `is_live` bool is the discriminator,
// never TikTok's numeric liveRoom.status (an offline account still ships its
// LAST room verbatim, status 4, with real enter/user counts).
//
// Trial-verified quirks absorbed here (recorded payloads 2026-09-01,
// charlidamelio offline + two husk shapes):
//  - a NotFound answer bills a credit as success:true and reads
//    {is_live: false, liveRoomUserInfo: {}} — BYTE-IDENTICAL to a real
//    account that has never streamed (shoplc). Neither positively supports
//    "offline", so offline is only ever asserted when liveRoomUserInfo
//    carries the account's own uniqueId; everything else is a vendor miss.
//  - room extras (title/watching/startedAt) are only trusted on a LIVE room:
//    the offline payload's liveRoom is the finished stream's residue.
class TiktokLiveNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{isLive: bool, handle: string, title: ?string, watching: ?int, startedAt: ?int}|null
     *                                                                                                   null unless the payload positively identifies the account —
     *                                                                                                   a billed husk must read "vendor miss / status unknown", never "offline".
     */
    public function normalize(array $body): ?array
    {
        $user = is_array($body['liveRoomUserInfo'] ?? null) ? $body['liveRoomUserInfo'] : [];
        $handle = $user['uniqueId'] ?? null;
        if (! is_bool($body['is_live'] ?? null) || ! is_string($handle) || trim($handle) === '') {
            return null;
        }

        $isLive = $body['is_live'] === true;
        $room = is_array($body['liveRoom'] ?? null) ? $body['liveRoom'] : [];
        $stats = is_array($room['liveRoomStats'] ?? null) ? $room['liveRoomStats'] : [];

        return [
            'isLive' => $isLive,
            'handle' => trim($handle),
            'title' => $isLive && is_string($room['title'] ?? null) && trim($room['title']) !== ''
                ? trim($room['title'])
                : null,
            'watching' => $isLive && is_numeric($stats['userCount'] ?? null) && (int) $stats['userCount'] > 0
                ? (int) $stats['userCount']
                : null,
            'startedAt' => $isLive && is_numeric($room['startTime'] ?? null) && (int) $room['startTime'] > 0
                ? (int) $room['startTime']
                : null,
        ];
    }
}
