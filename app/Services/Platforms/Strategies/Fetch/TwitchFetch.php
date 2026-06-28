<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\TwitchScraper;

// Re-scrapes a Twitch channel's display name, avatar, and bio by stored login.
// Mirrors PlatformRefresher::twitchPayload EXACTLY (scraped fields fall back to
// stored values; no feed items — the live embed is built sitepage-side).
final readonly class TwitchFetch implements FetchStrategy
{
    public function __construct(private TwitchScraper $twitch) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $login = $payload['login'] ?? null;
        if (! $login) {
            throw new FetchShapeException('missing_key: login');
        }

        $channel = $this->twitch->fetchChannel($login);
        if ($channel === null) {
            throw new FetchUnavailableException('twitch_fetch_failed');
        }

        return [
            ...$payload,
            'name' => $channel['name'] ?? ($payload['name'] ?? null),
            'image' => $channel['image'] ?? ($payload['image'] ?? null),
            'description' => $channel['description'] ?? ($payload['description'] ?? null),
        ];
    }
}
