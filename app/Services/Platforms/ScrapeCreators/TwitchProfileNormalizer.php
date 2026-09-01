<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10a (2026-09-01): /v1/twitch/profile → the identity card for Twitch's
// upgrade from link-only to data source. Unlike the Instagram normalizer this
// is NOT an aliasing pass — the vendor body is Twitch GraphQL riddled with
// __typename markers and credits_* billing fields, and Twitch had no incumbent
// scraper whose shape downstream already reads — so the card is SYNTHESIZED,
// never spread, and this class's output IS the contract.
//
// Trial-verified quirks absorbed here (recorded payloads 2026-09-01, pokimane
// + jynxzi):
//  - sibling social links are OPTIONAL TOP-LEVEL keys (instagram/youtube/
//    tiktok/twitter/facebook, full URLs), omitted — not null — when the
//    channel links nothing (jynxzi/ishowspeed carry none, pokimane all five).
//    They surface as `socialLinks` for the platform-detection layer.
//  - offline profiles answer stream: {} (empty object) with
//    currentViewersCount: null — the live block must gate on isLive === true,
//    never on the stream key existing.
//  - allVideos/featuredClips ride in the same billed answer; content is
//    deliberately NOT read from them here — VODs come from the videos
//    endpoint (TwitchVideosNormalizer), one content source per platform.
class TwitchProfileNormalizer
{
    /**
     * The optional top-level sibling-link keys observed live. A fixed list,
     * not a wildcard harvest: the body also carries handle-shaped junk keys
     * (cached, success) and any future vendor addition should be adopted
     * deliberately, not scooped up as a "social link".
     */
    private const SOCIAL_KEYS = ['instagram', 'youtube', 'tiktok', 'twitter', 'x', 'facebook', 'snapchat', 'discord', 'linkedin'];

    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array<string, mixed>|null null unless the payload positively
     *                                   carries a channel (id + handle) — a NotFound husk bills a credit as
     *                                   success:true and must read as "vendor miss", never an empty channel.
     */
    public function normalize(array $body): ?array
    {
        $id = $body['id'] ?? null;
        $login = $body['handle'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($login) || trim($login) === '') {
            return null;
        }
        $login = strtolower(trim($login));

        $stream = is_array($body['stream'] ?? null) ? $body['stream'] : [];
        $isLive = ($body['isLive'] ?? null) === true;
        $streamGame = is_array($stream['game'] ?? null) ? $stream['game'] : [];

        $socialLinks = [];
        foreach (self::SOCIAL_KEYS as $key) {
            $url = $body[$key] ?? null;
            if (is_string($url) && preg_match('~^https?://~i', $url) === 1) {
                $socialLinks[$key] = $url;
            }
        }

        return [
            'login' => $login,
            'displayName' => is_string($body['displayName'] ?? null) && trim($body['displayName']) !== ''
                ? trim($body['displayName'])
                : $login,
            'url' => 'https://www.twitch.tv/'.$login,
            'avatar' => $this->url($body['profileImageURL'] ?? null),
            'banner' => $this->url($body['bannerImageURL'] ?? null),
            'bio' => is_string($body['description'] ?? null) && trim($body['description']) !== ''
                ? trim($body['description'])
                : null,
            'followers' => is_numeric($body['followers'] ?? null) ? (int) $body['followers'] : null,
            'isPartner' => ($body['isPartner'] ?? null) === true,
            // The live-badge read Item 10a promises. The consolidation of
            // CheckStreamingLiveStatusJob onto this lane is Item 11d — a
            // separate unit; nothing here polls.
            'isLive' => $isLive,
            'liveViewers' => $isLive && is_numeric($body['currentViewersCount'] ?? ($stream['viewersCount'] ?? null))
                ? (int) ($body['currentViewersCount'] ?? $stream['viewersCount'])
                : null,
            'liveGame' => $isLive && is_string($streamGame['displayName'] ?? null) ? $streamGame['displayName'] : null,
            'liveStartedAt' => $isLive && is_string($stream['createdAt'] ?? null) ? $stream['createdAt'] : null,
            'socialLinks' => $socialLinks,
        ];
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^https?://~i', $value) === 1 ? $value : null;
    }
}
