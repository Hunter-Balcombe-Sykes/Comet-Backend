<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\YoutubeScraper;

// Re-pulls a YouTube channel's latest video by stored handle. Preserves handle +
// refreshes only the auto-latest tile + the flat
// header. Mirrors PlatformRefresher::youtubePayload EXACTLY.
final readonly class YoutubeFetch implements FetchStrategy
{
    public function __construct(private YoutubeScraper $youtube) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        // Router-created connections store the resolved identity as `username`
        // (ConnectionPayload's contract with the public wire), not `handle`.
        // The shim lives HERE, at the legacy reader — widening the router's
        // key set to satisfy this lane would push compatibility into the
        // canonical writer. The guards mirror the writer's own handle test:
        // non-empty, no slash. An empty `username` still throws — the router
        // resolving an empty identifier is a write defect (defect B) and
        // accepting "" would turn that loud failure into a vendor error.
        $handle = $payload['handle'] ?? null;
        if (! $handle) {
            $username = $payload['username'] ?? null;
            if (is_string($username) && $username !== '' && ! str_contains($username, '/')) {
                $handle = $username;
            }
        }
        if (! $handle) {
            throw new FetchShapeException('missing_key: handle');
        }

        $videos = $this->youtube->fetchRecentVideos($handle);
        if (empty($videos)) {
            throw new FetchUnavailableException('youtube_no_videos');
        }
        $latest = $videos[0];

        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            // Private picker snapshot (HighlightsPicker::SNAPSHOT_KEY) — up to 15,
            // matching YoutubeHighlights' picker width. Refreshed every 12h so the
            // picker rarely needs a live re-fetch.
            'recent' => array_slice($videos, 0, 15),
        ];
    }
}
