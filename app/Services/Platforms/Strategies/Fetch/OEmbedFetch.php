<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Closure;

// Shared fetch for the oEmbed music embeds (Spotify, SoundCloud). Re-resolves
// name + artwork + embed URL from the platform's public oEmbed endpoint; the stored
// link/url is the stable input. Mirrors PlatformRefresher::musicEmbedPayload EXACTLY
// — same link-key precedence (link ?? url), same spread-merge with the existing
// payload — so the Plan-6 refresher swap is behaviour-preserving.
final readonly class OEmbedFetch implements FetchStrategy
{
    /** @param Closure(string):string $endpointFor builds the oEmbed endpoint URL from the stored link */
    public function __construct(
        private OEmbedService $oembed,
        private Closure $endpointFor,
        private string $platform,
    ) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $link = $payload['link'] ?? $payload['url'] ?? null;
        if (! $link) {
            throw new FetchShapeException('missing_key: link');
        }

        $cond = ConditionalContext::for($connection);
        $resolved = $this->oembed->resolve(($this->endpointFor)($link), $cond);

        if ($cond?->notModified) {
            throw new FetchNotModifiedException($this->platform);
        }
        if ($resolved === null) {
            throw new FetchUnavailableException("{$this->platform}_oembed_failed");
        }
        $cond?->applyTo($connection);

        return [
            ...$payload,
            'name' => $resolved['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $resolved['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => $resolved['embedUrl'] ?? ($payload['embedUrl'] ?? null),
        ];
    }
}
