<?php

namespace App\Services\Platforms;

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TwitchProfileNormalizer;
use App\Services\Platforms\ScrapeCreators\TwitchVideosNormalizer;
use Illuminate\Support\Facades\Log;

// Item 10a (2026-09-01): Twitch's upgrade from link-only to data source —
// identity + recent VODs for the watch pool, the live-badge read, and the
// channel's sibling social links as detection input. The seam mirrors the
// watch lane's YouTube vendor-fronting (YoutubeScraper::vendorUploadsFeed),
// with one structural difference: ScrapeCreators is the ONLY fetch path.
// The old TwitchScraper was deleted as dead code when the platform went
// link-only (f4d7c3b0f), so unlike Instagram there is no incumbent to fall
// through to — every miss (no key, budget denied, transport, husk, shape
// drift) returns null and the caller degrades to Unavailable, never to
// "this channel is empty". TwitchApiClient (Helix) stays untouched in the
// live-status lane; whether Item 11d folds that poller onto this vendor is
// that unit's call.
//
// Budget contract (Item 8 adapter notes): claim BEFORE the call, release on
// transport-null, keep the slot spent on billed husks — NotFound bills a
// credit as success:true, so the gate is payload shape, never HTTP status.
// Lane is dormant until partna.limits.scrapecreators.sources.twitch lands
// (an absent cap reads 0 and never claims).
class TwitchScraper
{
    /** Twitch's own login format — TwitchNormalizer's rule, same source. */
    private const LOGIN_PATTERN = '~^[a-z0-9_]{3,25}$~';

    public function __construct(
        private readonly ScrapeCreatorsClient $client,
        private readonly ScrapeCreatorsBudget $budget,
        private readonly TwitchProfileNormalizer $profiles,
        private readonly TwitchVideosNormalizer $videos,
    ) {}

    /**
     * The channel's identity card: login/displayName/avatar/banner/bio/
     * followers, the isLive read (Item 11d's consolidation input — nothing
     * here polls), and socialLinks for the detection layer. Shape:
     * TwitchProfileNormalizer.
     *
     * @return array<string, mixed>|null
     */
    public function fetchProfile(string $login, ?string $userId = null): ?array
    {
        $login = $this->normalizeLogin($login);
        if ($login === null || ! $this->client->enabled() || ! $this->budget->tryClaim('twitch')) {
            return null;
        }

        $body = $this->client->get('/v1/twitch/profile', ['handle' => $login], $userId);
        if ($body === null) {
            $this->budget->release('twitch');

            return null;
        }

        $profile = $this->profiles->normalize($body);
        if ($profile === null) {
            // Success-shaped husk or shape drift — billed either way, the
            // slot stays spent. Logins are public channel names, logged raw
            // like the YouTube lane's channel ids.
            Log::info('scrapecreators.twitch.unusable_shape', ['endpoint' => 'profile', 'login' => $login]);

            return null;
        }

        return $profile;
    }

    /**
     * The channel's most-recent VODs, newest first, up to $limit — watch-pool
     * rows (shape: TwitchVideosNormalizer). One page only: the endpoint
     * answers up to 100 per call and the watch window keeps ~15, so
     * pagination would only ever buy rows this method throws away.
     *
     * $filterBy narrows to one Twitch VOD type (ARCHIVE past broadcasts —
     * which Twitch expires after 7-60 days — HIGHLIGHT, or UPLOAD); null
     * means all types, and the choice of what the watch pool should hold
     * belongs to the connector unit, not here.
     *
     * @return list<array<string, mixed>>|null
     */
    public function fetchRecentVideos(string $login, int $limit = 15, ?string $filterBy = null, ?string $userId = null): ?array
    {
        $login = $this->normalizeLogin($login);
        if ($login === null || ! $this->client->enabled() || ! $this->budget->tryClaim('twitch')) {
            return null;
        }

        $query = ['handle' => $login, 'sort_by' => 'TIME'];
        if ($filterBy !== null) {
            $query['filter_by'] = $filterBy;
        }

        $body = $this->client->get('/v1/twitch/user/videos', $query, $userId);
        if ($body === null) {
            $this->budget->release('twitch');

            return null;
        }

        $rows = $this->videos->rows($body);
        if ($rows === null) {
            Log::info('scrapecreators.twitch.unusable_shape', ['endpoint' => 'user/videos', 'login' => $login]);

            return null;
        }

        usort($rows, static fn (array $a, array $b) => strcmp((string) ($b['published'] ?? ''), (string) ($a['published'] ?? '')));

        return array_slice($rows, 0, max(1, $limit));
    }

    /** Bare login or @login → the canonical lowercase form; null refuses the call before any claim. */
    private function normalizeLogin(string $login): ?string
    {
        $login = strtolower(ltrim(trim($login), '@'));

        return preg_match(self::LOGIN_PATTERN, $login) === 1 ? $login : null;
    }
}
