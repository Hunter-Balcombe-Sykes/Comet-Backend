<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;

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
    public const REFRESHABLE = ['youtube', 'eventbrite', 'apple-music', 'apple-podcast'];

    public function __construct(
        private readonly YoutubeScraper $youtube,
        private readonly EventbriteScraper $eventbrite,
        private readonly AppleSearch $apple,
    ) {}

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        $payload = $connection->payload ?? [];

        // Each *Payload method returns {payload: array|null, error: string|null}.
        // On failure, payload is null and error holds a terse snake_case reason
        // for Nightwatch/operator forensics.
        $result = match ($connection->platform) {
            'youtube' => $this->youtubePayload($payload),
            'eventbrite' => $this->eventbritePayload($payload),
            'apple-music' => $this->appleMusicPayload($payload),
            'apple-podcast' => $this->applePodcastPayload($payload),
            default => ['payload' => null, 'error' => 'unsupported_platform'],
        };

        $next = $result['payload'];
        $error = $result['error'];

        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
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
     * @return array{payload: array<string,mixed>|null, error: string|null}
     */
    private function youtubePayload(array $payload): array
    {
        $handle = $payload['handle'] ?? null;
        if (! $handle) {
            return ['payload' => null, 'error' => 'missing_handle'];
        }
        $videos = $this->youtube->fetchRecentVideos($handle);
        if (empty($videos)) {
            return ['payload' => null, 'error' => 'youtube_no_videos'];
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
        ], 'error' => null];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null}
     */
    private function eventbritePayload(array $payload): array
    {
        $url = $payload['url'] ?? null;
        if (! $url) {
            return ['payload' => null, 'error' => 'missing_url'];
        }
        $result = $this->eventbrite->fetchEvents($url);
        if ($result === null) {
            return ['payload' => null, 'error' => 'eventbrite_fetch_failed'];
        }
        $events = $result['events'];

        return ['payload' => [
            'url' => $url,
            'organiser' => $result['organiser'],
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ], 'error' => null];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null}
     */
    private function appleMusicPayload(array $payload): array
    {
        $input = $payload['input'] ?? null;
        if (! $input) {
            return ['payload' => null, 'error' => 'missing_input'];
        }
        $albums = $this->apple->fetchAlbums($input);
        if (empty($albums)) {
            return ['payload' => null, 'error' => 'apple_music_no_albums'];
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
        ], 'error' => null];
    }

    /**
     * @return array{payload: array<string,mixed>|null, error: string|null}
     */
    private function applePodcastPayload(array $payload): array
    {
        $input = $payload['input'] ?? null;
        if (! $input) {
            return ['payload' => null, 'error' => 'missing_input'];
        }
        $episodes = $this->apple->fetchEpisodes($input);
        if (empty($episodes)) {
            return ['payload' => null, 'error' => 'apple_podcast_no_episodes'];
        }
        $latest = $episodes[0];

        return ['payload' => [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'description' => $latest['description'],
            'link' => $latest['link'],
        ], 'error' => null];
    }
}
