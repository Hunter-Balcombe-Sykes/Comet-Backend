<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Normalizers\TwitchNormalizer;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\TwitchScraper;

// Twitch connect (Item 10a, 2026-09-01): channel URL or bare login →
// TwitchNormalizer validation, then ONE ScrapeCreators profile call puts the
// channel's identity onto the stored payload — the VimeoConnect pattern
// (identity at connect time), bounded like every connect by ConnectResolver's
// FetchBudget. The eager ingest run that follows the row write fetches VODs
// only, so a connect costs at most two vendor credits total.
//
// The vendor is an ENRICHMENT here, never a gate: Twitch connected link-only
// for months before this upgrade, and a missing key / exhausted budget /
// transport miss / NotFound husk must not start refusing connects that used
// to succeed. Every profile miss degrades to the exact link-only payload
// UrlConnect(TwitchNormalizer) produced — TwitchScraper already folds all
// four misses to null and keeps the Item 8 budget mechanics (claim before
// call, release on transport-null, slot spent on billed husks).
//
// Deliberately a plain ConnectStrategy, not DeferredConnect: the deferred
// path's ConnectFetchJob fills payloads through the descriptor's registered
// FetchStrategy, and Twitch has none — declaring the seam would wire a 202
// whose fill job can only no-op.
//
// The live block (isLive/liveViewers/liveGame/liveStartedAt) is a connect-time
// snapshot stamped with liveCheckedAt so consumers can judge staleness; the
// job of keeping it FRESH is Item 11d's unified live-status lane, not a
// hidden poller here. socialLinks (full URLs, TwitchProfileNormalizer's fixed
// key list) ride the payload as the detection layer's input.
class TwitchConnect implements ConnectStrategy
{
    public function __construct(private readonly TwitchScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $parsed = (new TwitchNormalizer)($input);
        if ($parsed === null) {
            return ConnectResult::fail(); // descriptor's frozen 422 copy
        }

        // {username, url} — the exact link-only selection this strategy
        // replaces, so a vendor miss stores a payload byte-compatible with
        // every twitch row that came before it.
        $payload = $parsed;

        $profile = $this->scraper->fetchProfile($parsed['username']);
        if ($profile !== null) {
            $payload += [
                // FeedPayload vocabulary where a key exists there (name/
                // thumbnail/description/followers survive the resource read);
                // the twitch-specific block below is stored-only detection and
                // badge input.
                'name' => $profile['displayName'],
                'thumbnail' => $profile['avatar'],
                'banner' => $profile['banner'],
                'description' => $profile['bio'],
                'followers' => $profile['followers'],
                'isPartner' => $profile['isPartner'],
                'isLive' => $profile['isLive'],
                'liveViewers' => $profile['liveViewers'],
                'liveGame' => $profile['liveGame'],
                'liveStartedAt' => $profile['liveStartedAt'],
                'liveCheckedAt' => now()->toIso8601String(),
                'socialLinks' => $profile['socialLinks'],
            ];
        }

        // accountKey explicit: the canonical lowercase login, so a future
        // multi-account upgrade keys rows on the channel, not on input casing.
        return ConnectResult::ok($payload, $parsed['username']);
    }
}
