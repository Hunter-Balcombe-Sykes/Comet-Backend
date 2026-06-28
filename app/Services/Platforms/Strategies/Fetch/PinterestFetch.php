<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\PinterestScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls a Pinterest profile (name/avatar/followers) + latest pins by stored
// username. Mirrors PlatformRefresher::pinterestPayload EXACTLY (pins fall back to
// the stored latest/items when the RSS is empty).
final readonly class PinterestFetch implements FetchStrategy
{
    public function __construct(private PinterestScraper $pinterest) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $username = $payload['username'] ?? null;
        if (! $username) {
            throw new FetchShapeException('missing_key: username');
        }

        $profile = $this->pinterest->fetchProfile($username);
        if ($profile === null) {
            throw new FetchUnavailableException('pinterest_fetch_failed');
        }
        $pins = $this->pinterest->fetchPins($username);

        return [
            ...$payload,
            'name' => $profile['name'] ?? ($payload['name'] ?? null),
            'image' => $profile['image'] ?? ($payload['image'] ?? null),
            'followers' => $profile['followers'] ?? ($payload['followers'] ?? null),
            'latest' => $pins[0] ?? ($payload['latest'] ?? null),
            'items' => $pins !== [] ? $pins : ($payload['items'] ?? []),
        ];
    }
}
