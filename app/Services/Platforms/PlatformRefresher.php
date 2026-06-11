<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

// Pilot daily refresh for the cheap auto-content platforms — re-fetches the
// latest YouTube video, Eventbrite events, and Apple latest release so sitepages
// stay current without the user re-connecting. The command only feeds these four
// platforms in: static links (TikTok/Facebook) have nothing to refresh, and the
// costly/multi-step ones (Instagram = paid Apify, Fresha + Shopify = multi-step
// scrapes) are deferred to the backend dev's hardening pass.
//
// Mirrors SmartLinkRefresher: persists the outcome and returns the model with
// last_refresh_status set. 'ok' updates the payload through the model so
// IntegrationConnectionObserver purges the sitepage edge cache; a failed fetch is
// recorded quietly, keeping the last-known-good payload (no purge — nothing
// changed for the sitepage).
class PlatformRefresher
{
    public const REFRESHABLE = [
        'youtube', 'youtube-music', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'bandcamp', 'spotify', 'soundcloud', 'deezer',
        'vimeo', 'twitch', 'pinterest', 'strava', 'google-business',
    ];

    public function __construct(
        private readonly YoutubeScraper $youtube,
        private readonly EventbriteScraper $eventbrite,
        private readonly HumanitixScraper $humanitix,
        private readonly AppleSearch $apple,
        private readonly BandcampScraper $bandcamp,
        private readonly OEmbedService $oembed,
        private readonly DeezerApi $deezer,
        private readonly VimeoApi $vimeo,
        private readonly TwitchScraper $twitch,
        private readonly PinterestScraper $pinterest,
        private readonly StravaClubScraper $strava,
        private readonly GoogleBusinessService $googleBusiness,
    ) {}

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        $payload = $connection->payload ?? [];

        // Each *Payload method returns {payload, error, status}.
        // status='error'     → bad config/shape (missing required key); flag loudly.
        // status='unavailable' → transient empty scrape / fetch failure; record quietly.
        // status='ok'        → success.
        $result = match ($connection->platform) {
            'youtube' => $this->youtubePayload($payload),
            'youtube-music' => $this->youtubeMusicPayload($payload),
            'eventbrite' => $this->eventbritePayload($payload),
            'humanitix' => $this->humanitixPayload($payload),
            'apple-music' => $this->appleMusicPayload($payload),
            'apple-podcast' => $this->applePodcastPayload($payload),
            'bandcamp' => $this->bandcampPayload($payload),
            'spotify' => $this->musicEmbedPayload($payload, fn (string $link) => 'https://open.spotify.com/oembed?url='.rawurlencode($link), 'spotify'),
            'soundcloud' => $this->musicEmbedPayload($payload, fn (string $link) => 'https://soundcloud.com/oembed?format=json&url='.rawurlencode($link), 'soundcloud'),
            'deezer' => $this->deezerPayload($payload),
            'vimeo' => $this->vimeoPayload($payload),
            'twitch' => $this->twitchPayload($payload),
            'pinterest' => $this->pinterestPayload($payload),
            'strava' => $this->scrapedCardPayload($payload, fn (string $url) => $this->strava->fetchClub($url), 'strava'),
            'google-business' => $this->googleBusinessPayload($payload),
            default => ['payload' => null, 'error' => 'unsupported_platform', 'status' => 'error'],
        };

        $next = $result['payload'];

        if ($next === null) {
            $status = $result['status'];
            $error = $result['error'];

            // A shape error (missing required key) is a data-integrity problem, not a
            // transient outage — flag it as 'error' and surface it so it doesn't hide in
            // the same 'unavailable' bucket as a normal empty scrape.
            if ($status === 'error') {
                Log::warning('integrations.refresh.bad_shape', [
                    'platform' => $connection->platform,
                    'platform_connection_id' => $connection->id,
                    'error' => $error,
                ]);
            }

            $connection->forceFill([
                'last_refresh_status' => $status,
                'last_refresh_error' => $error,
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();

            return $connection;
        }

        $connection->update([
            'payload' => $next,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);

        return $connection;
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function youtubePayload(array $payload): array
    {
        $handle = $payload['handle'] ?? null;
        if (! $handle) {
            return ['payload' => null, 'error' => 'missing_key: handle', 'status' => 'error'];
        }
        $videos = $this->youtube->fetchRecentVideos($handle);
        if (empty($videos)) {
            return ['payload' => null, 'error' => 'youtube_no_videos', 'status' => 'unavailable'];
        }
        $latest = $videos[0];

        // Preserve handle + curated highlights via spread; refresh only the
        // auto-latest tile. `latest` is the canonical nested shape the dashboard
        // "Most Recent" tile reads — it MUST survive the refresh (drift caused by
        // reconstructing-from-scratch is the bug this fixes).
        return ['payload' => [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function eventbritePayload(array $payload): array
    {
        $url = $payload['url'] ?? null;
        if (! $url) {
            return ['payload' => null, 'error' => 'missing_key: url', 'status' => 'error'];
        }
        $result = $this->eventbrite->fetchEvents($url);
        if ($result === null) {
            return ['payload' => null, 'error' => 'eventbrite_fetch_failed', 'status' => 'unavailable'];
        }
        $events = $result['events'];

        return ['payload' => [
            'url' => $url,
            'organiser' => $result['organiser'],
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function humanitixPayload(array $payload): array
    {
        $url = $payload['url'] ?? null;
        if (! $url) {
            return ['payload' => null, 'error' => 'missing_key: url', 'status' => 'error'];
        }
        $result = $this->humanitix->fetchEvents($url);
        if ($result === null) {
            return ['payload' => null, 'error' => 'humanitix_fetch_failed', 'status' => 'unavailable'];
        }
        $events = $result['events'];

        return ['payload' => [
            'url' => $url,
            'organiser' => $result['organiser'],
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function bandcampPayload(array $payload): array
    {
        $url = $payload['url'] ?? null;
        if (! $url) {
            return ['payload' => null, 'error' => 'missing_key: url', 'status' => 'error'];
        }
        $profile = $this->bandcamp->fetchProfile($url);
        if ($profile === null || $profile['items'] === []) {
            return ['payload' => null, 'error' => 'bandcamp_no_releases', 'status' => 'unavailable'];
        }
        $latest = $profile['items'][0];

        // Preserve url + curated highlights; refresh the artist name and the
        // auto-latest tile (flat fields mirror the connect shape).
        return ['payload' => [
            ...$payload,
            'artist' => $profile['name'] ?? ($payload['artist'] ?? null),
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'] ?? $profile['thumbnail'],
            'link' => $latest['link'],
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * Spotify / SoundCloud share the oEmbed re-resolve: name + artwork can
     * change upstream; the link + embed URL are stable.
     *
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function musicEmbedPayload(array $payload, callable $endpointFor, string $platform): array
    {
        $link = $payload['link'] ?? $payload['url'] ?? null;
        if (! $link) {
            return ['payload' => null, 'error' => 'missing_key: link', 'status' => 'error'];
        }
        $resolved = $this->oembed->resolve($endpointFor($link));
        if ($resolved === null) {
            return ['payload' => null, 'error' => "{$platform}_oembed_failed", 'status' => 'unavailable'];
        }

        return ['payload' => [
            ...$payload,
            'name' => $resolved['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $resolved['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => $resolved['embedUrl'] ?? ($payload['embedUrl'] ?? null),
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function appleMusicPayload(array $payload): array
    {
        $input = $payload['input'] ?? null;
        if (! $input) {
            return ['payload' => null, 'error' => 'missing_key: input', 'status' => 'error'];
        }
        $albums = $this->apple->fetchAlbums($input);
        if (empty($albums)) {
            return ['payload' => null, 'error' => 'apple_music_no_albums', 'status' => 'unavailable'];
        }
        $latest = $albums[0];

        // Preserve input + curated highlights; refresh only the "most recent" tile.
        return ['payload' => [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function applePodcastPayload(array $payload): array
    {
        $input = $payload['input'] ?? null;
        if (! $input) {
            return ['payload' => null, 'error' => 'missing_key: input', 'status' => 'error'];
        }
        $episodes = $this->apple->fetchEpisodes($input);
        if (empty($episodes)) {
            return ['payload' => null, 'error' => 'apple_podcast_no_episodes', 'status' => 'unavailable'];
        }
        $latest = $episodes[0];

        return ['payload' => [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'description' => $latest['description'],
            'link' => $latest['link'],
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function deezerPayload(array $payload): array
    {
        $id = $payload['artistId'] ?? null;
        if (! $id) {
            return ['payload' => null, 'error' => 'missing_key: artistId', 'status' => 'error'];
        }
        $artist = $this->deezer->fetchArtist((string) $id);
        if ($artist === null) {
            return ['payload' => null, 'error' => 'deezer_fetch_failed', 'status' => 'unavailable'];
        }

        // Link is stable; name and artwork can change upstream. embedUrl is
        // recomputed so rows stored before the /top_tracks fix self-heal.
        return ['payload' => [
            ...$payload,
            'name' => $artist['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $artist['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => DeezerApi::embedUrlForArtist((string) $id),
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function youtubeMusicPayload(array $payload): array
    {
        $channelId = $payload['channelId'] ?? null;
        if (! $channelId) {
            return ['payload' => null, 'error' => 'missing_key: channelId', 'status' => 'error'];
        }
        $feed = $this->youtube->fetchUploadsFeed((string) $channelId, 12);
        if ($feed === null || $feed['videos'] === []) {
            return ['payload' => null, 'error' => 'youtube_music_no_releases', 'status' => 'unavailable'];
        }
        $items = \App\Http\Controllers\Api\Platforms\YoutubeMusicController::musicItems($feed['videos']);

        return ['payload' => [
            ...$payload,
            'name' => $feed['title'] !== null
                ? preg_replace('/\s+-\s+Topic$/', '', $feed['title'])
                : ($payload['name'] ?? null),
            'thumbnail' => $items[0]['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'latest' => $items[0],
            'items' => array_slice($items, 0, 12),
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function vimeoPayload(array $payload): array
    {
        $apiPath = $payload['apiPath'] ?? null;
        if (! $apiPath) {
            return ['payload' => null, 'error' => 'missing_key: apiPath', 'status' => 'error'];
        }
        $videos = $this->vimeo->fetchVideos($apiPath);
        if ($videos === []) {
            return ['payload' => null, 'error' => 'vimeo_no_videos', 'status' => 'unavailable'];
        }
        $profile = $this->vimeo->fetchProfile($apiPath);

        return ['payload' => [
            ...$payload,
            'name' => $profile['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $profile['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'latest' => $videos[0],
            'items' => array_slice($videos, 0, 12),
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function twitchPayload(array $payload): array
    {
        $login = $payload['login'] ?? null;
        if (! $login) {
            return ['payload' => null, 'error' => 'missing_key: login', 'status' => 'error'];
        }
        $channel = $this->twitch->fetchChannel($login);
        if ($channel === null) {
            return ['payload' => null, 'error' => 'twitch_fetch_failed', 'status' => 'unavailable'];
        }

        return ['payload' => [
            ...$payload,
            'name' => $channel['name'] ?? ($payload['name'] ?? null),
            'image' => $channel['image'] ?? ($payload['image'] ?? null),
            'description' => $channel['description'] ?? ($payload['description'] ?? null),
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function pinterestPayload(array $payload): array
    {
        $username = $payload['username'] ?? null;
        if (! $username) {
            return ['payload' => null, 'error' => 'missing_key: username', 'status' => 'error'];
        }
        $profile = $this->pinterest->fetchProfile($username);
        if ($profile === null) {
            return ['payload' => null, 'error' => 'pinterest_fetch_failed', 'status' => 'unavailable'];
        }
        $pins = $this->pinterest->fetchPins($username);

        return ['payload' => [
            ...$payload,
            'name' => $profile['name'] ?? ($payload['name'] ?? null),
            'image' => $profile['image'] ?? ($payload['image'] ?? null),
            'followers' => $profile['followers'] ?? ($payload['followers'] ?? null),
            'latest' => $pins[0] ?? ($payload['latest'] ?? null),
            'items' => $pins !== [] ? $pins : ($payload['items'] ?? []),
        ], 'error' => null, 'status' => 'ok'];
    }

    /**
     * Strava (and any future single-URL card): the stored URL re-scraped
     * into card fields that merge over the existing payload.
     *
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function scrapedCardPayload(array $payload, callable $fetch, string $platform): array
    {
        $url = $payload['url'] ?? null;
        if (! $url) {
            return ['payload' => null, 'error' => 'missing_key: url', 'status' => 'error'];
        }
        $card = $fetch($url);
        if ($card === null) {
            return ['payload' => null, 'error' => "{$platform}_fetch_failed", 'status' => 'unavailable'];
        }

        // Refresh every scraped field; nulls from the scrape keep stored values.
        $merged = $payload;
        foreach ($card as $key => $value) {
            $merged[$key] = $value ?? ($payload[$key] ?? null);
        }

        return ['payload' => $merged, 'error' => null, 'status' => 'ok'];
    }

    /**
     * Google Business: re-pull the Place Details snapshot (rating, reviews,
     * hours, phone, …) by stored placeId. The cron is daily but the snapshot
     * only needs ~weekly re-pulls (Google billing + the ToS caching window),
     * so a fresh detailsFetchedAt short-circuits without an API call.
     *
     * @return array{payload: array<string,mixed>|null, error: string|null, status: string}
     */
    private function googleBusinessPayload(array $payload): array
    {
        $placeId = $payload['placeId'] ?? null;
        if (! $placeId) {
            // Legacy link-paste connections legitimately lack a placeId —
            // skip quietly rather than flag a shape error.
            return ['payload' => null, 'error' => 'missing_place_id', 'status' => 'unavailable'];
        }

        try {
            $fresh = isset($payload['detailsFetchedAt'])
                && Carbon::parse($payload['detailsFetchedAt'])->gt(now()->subDays(6));
        } catch (\Throwable) {
            $fresh = false;
        }
        if ($fresh) {
            return ['payload' => $payload, 'error' => null, 'status' => 'ok'];
        }

        $details = $this->googleBusiness->fetchPlaceDetails((string) $placeId);
        if ($details === null) {
            return ['payload' => null, 'error' => 'google_details_fetch_failed', 'status' => 'unavailable'];
        }

        // Spread preserves placeId + any stored keys the fetch didn't return.
        return ['payload' => [...$payload, ...$details], 'error' => null, 'status' => 'ok'];
    }
}
