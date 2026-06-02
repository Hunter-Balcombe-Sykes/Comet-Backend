<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\PlatformConnection;

// Pilot daily refresh for the cheap auto-content platforms — re-fetches the
// latest YouTube video, Eventbrite events, and Apple latest release so sitepages
// stay current without the user re-connecting. The command only feeds these four
// platforms in: static links (TikTok/Facebook) have nothing to refresh, and the
// costly/multi-step ones (Instagram = paid Apify, Fresha + Shopify = multi-step
// scrapes) are deferred to the backend dev's hardening pass.
//
// Mirrors SmartLinkRefresher: persists the outcome and returns the model with
// last_refresh_status set. 'ok' updates the payload through the model so
// PlatformConnectionObserver purges the sitepage edge cache; a failed fetch is
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

    public function refresh(PlatformConnection $connection): PlatformConnection
    {
        $payload = $connection->payload ?? [];

        $next = match ($connection->platform) {
            'youtube' => $this->youtubePayload($payload),
            'eventbrite' => $this->eventbritePayload($payload),
            'apple-music' => $this->appleMusicPayload($payload),
            'apple-podcast' => $this->applePodcastPayload($payload),
            default => null,
        };

        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
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

    private function youtubePayload(array $payload): ?array
    {
        $handle = $payload['handle'] ?? null;
        if (! $handle) {
            return null;
        }
        $videos = $this->youtube->fetchRecentVideos($handle);
        if (empty($videos)) {
            return null;
        }
        $latest = $videos[0];

        return [
            'handle' => $handle,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            // User-chosen highlights are preserved — the cron only refreshes the
            // auto-latest tile, not the curated picks.
            'highlights' => $payload['highlights'] ?? [],
        ];
    }

    private function eventbritePayload(array $payload): ?array
    {
        $url = $payload['url'] ?? null;
        if (! $url) {
            return null;
        }
        $result = $this->eventbrite->fetchEvents($url);
        if ($result === null) {
            return null;
        }
        $events = $result['events'];

        return [
            'url' => $url,
            'organiser' => $result['organiser'],
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ];
    }

    private function appleMusicPayload(array $payload): ?array
    {
        $input = $payload['input'] ?? null;
        if (! $input) {
            return null;
        }
        $albums = $this->apple->fetchAlbums($input);
        if (empty($albums)) {
            return null;
        }
        $latest = $albums[0];

        // Preserve input + curated highlights; refresh only the "most recent" tile.
        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
        ];
    }

    private function applePodcastPayload(array $payload): ?array
    {
        $input = $payload['input'] ?? null;
        if (! $input) {
            return null;
        }
        $episodes = $this->apple->fetchEpisodes($input);
        if (empty($episodes)) {
            return null;
        }
        $latest = $episodes[0];

        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'description' => $latest['description'],
            'link' => $latest['link'],
        ];
    }
}
