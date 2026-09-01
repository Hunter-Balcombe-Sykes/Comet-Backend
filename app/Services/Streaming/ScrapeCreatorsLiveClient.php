<?php

namespace App\Services\Streaming;

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TiktokLiveNormalizer;
use App\Services\Platforms\ScrapeCreators\TwitchProfileNormalizer;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Log;

// Item 11d (2026-09-01): the vendor leg of the unified live-status poller —
// one ?bool per (platform, handle), where null ALWAYS means "vendor miss /
// status unknown" and never "offline". LiveStatusPoller owns what a miss
// degrades to (Helix fallback for Twitch, keep-prior-status for the rest);
// this class owns only fetch + shape-gate + budget.
//
// Budget contract (Item 8 adapter notes): claim BEFORE the call, release on
// transport-null, keep the slot spent on billed husks — NotFound bills a
// credit as success:true, so the gate is payload shape, never HTTP status.
// The live lane claims its OWN sources ('twitch_live'/'tiktok_live', and
// 'youtube_lives' inside YoutubeScraper::fetchLives) — an every-2-min poll
// cadence must never drain the content lanes' scrape budgets, the same
// separation Item 11c pinned for YouTube. Lanes are dormant until their
// partna.limits.scrapecreators.sources.* caps land (absent cap reads 0).
class ScrapeCreatorsLiveClient
{
    /** TwitchScraper::LOGIN_PATTERN's rule, same source of truth (Twitch login format). */
    private const TWITCH_LOGIN_PATTERN = '~^[a-z0-9_]{3,25}$~';

    /** TikTok's own username format — refuse junk before a claim is spent. */
    private const TIKTOK_HANDLE_PATTERN = '~^[a-z0-9._]{2,24}$~';

    public function __construct(
        private readonly ScrapeCreatorsClient $client,
        private readonly ScrapeCreatorsBudget $budget,
        private readonly TwitchProfileNormalizer $twitchProfiles,
        private readonly TiktokLiveNormalizer $tiktokLives,
        private readonly YoutubeScraper $youtube,
    ) {}

    /**
     * True/false is a positively-supported status; null is a miss (no key,
     * budget denied, transport failure, billed husk, shape drift, unknown
     * platform) and the caller falls through or keeps the prior status.
     */
    public function isLive(string $platform, string $handle): ?bool
    {
        return match ($platform) {
            'twitch' => $this->twitchIsLive($handle),
            'tiktok' => $this->tiktokIsLive($handle),
            'youtube' => $this->youtubeIsLive($handle),
            default => null,
        };
    }

    private function twitchIsLive(string $handle): ?bool
    {
        $login = strtolower(ltrim(trim($handle), '@'));
        if (preg_match(self::TWITCH_LOGIN_PATTERN, $login) !== 1) {
            return null;
        }

        $body = $this->fetch('twitch_live', '/v1/twitch/profile', ['handle' => $login]);
        if ($body === null) {
            return null;
        }

        $profile = $this->twitchProfiles->normalize($body);
        if ($profile === null) {
            Log::info('scrapecreators.live.unusable_shape', ['platform' => 'twitch', 'handle' => $login]);

            return null;
        }

        return $profile['isLive'] === true;
    }

    private function tiktokIsLive(string $handle): ?bool
    {
        $username = strtolower(ltrim(trim($handle), '@'));
        if (preg_match(self::TIKTOK_HANDLE_PATTERN, $username) !== 1) {
            return null;
        }

        $body = $this->fetch('tiktok_live', '/v1/tiktok/user/live', ['handle' => $username]);
        if ($body === null) {
            return null;
        }

        $status = $this->tiktokLives->normalize($body);
        if ($status === null) {
            Log::info('scrapecreators.live.unusable_shape', ['platform' => 'tiktok', 'handle' => $username]);

            return null;
        }

        return $status['isLive'] === true;
    }

    private function youtubeIsLive(string $handle): ?bool
    {
        // Budget ('youtube_lives'), UC-id/handle param routing and the
        // populated-Live-tab-only offline rule all live in fetchLives.
        $result = $this->youtube->fetchLives($handle);

        return $result === null ? null : $result['isLive'] === true;
    }

    /**
     * The shared claim → call → release-on-transport-null ladder.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null
     */
    private function fetch(string $source, string $path, array $query): ?array
    {
        if (! $this->client->enabled() || ! $this->budget->tryClaim($source)) {
            return null;
        }

        $body = $this->client->get($path, $query);
        if ($body === null) {
            $this->budget->release($source);

            return null;
        }

        return $body;
    }
}
