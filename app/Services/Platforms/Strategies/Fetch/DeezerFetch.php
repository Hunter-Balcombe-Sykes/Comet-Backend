<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-resolves a Deezer artist's name + artwork by stored artistId. The link is
// stable; embedUrl is always recomputed via DeezerApi::embedUrlForArtist (self-heals
// rows stored before the /top_tracks fix). Mirrors PlatformRefresher::deezerPayload
// EXACTLY so the Plan-6 refresher swap is behaviour-preserving.
final readonly class DeezerFetch implements FetchStrategy
{
    public function __construct(private DeezerApi $deezer) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $id = $payload['artistId'] ?? null;
        if (! $id) {
            throw new FetchShapeException('missing_key: artistId');
        }

        $cond = ConditionalContext::for($connection);
        $artist = $this->deezer->fetchArtist((string) $id, $cond);

        if ($cond?->notModified) {
            throw new FetchNotModifiedException('deezer');
        }
        if ($artist === null) {
            throw new FetchUnavailableException('deezer_fetch_failed');
        }
        $cond?->applyTo($connection);

        return [
            ...$payload,
            'name' => $artist['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $artist['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => DeezerApi::embedUrlForArtist((string) $id),
        ];
    }
}
