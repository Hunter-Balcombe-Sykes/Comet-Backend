<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\SpotifyPodcastsScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Item 11f (2026-09-01): re-pulls a Spotify show's identity card by the
// stored link — the refresher leg of SpotifyPodcastsConnect, and the payload
// fill for its deferred (202) path. Same link-key precedence as OEmbedFetch
// (link ?? url) so the pending row identify() writes is exactly what this
// strategy reads. Every fetch is a billed vendor call under the
// spotify_podcasts cap (claimed inside the scraper), so a refresh cadence
// here is a spend decision, not a free poll.
final readonly class SpotifyPodcastsFetch implements FetchStrategy
{
    public function __construct(private SpotifyPodcastsScraper $scraper) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $link = $payload['link'] ?? $payload['url'] ?? null;
        if (! $link) {
            throw new FetchShapeException('missing_key: link');
        }

        $card = $this->scraper->fetchShow((string) $link);
        if ($card === null) {
            // No key / budget denied / transport / husk all fold here — the
            // lossy-vendor contract; the stored card keeps rendering.
            throw new FetchUnavailableException('spotify_podcasts_show_unavailable');
        }

        return [
            ...$payload,
            // The card's derived url (no ?si= share token) re-canonicalises a
            // link stored from an intl-prefixed paste.
            'url' => $card['url'],
            'link' => $card['url'],
            'name' => $card['name'],
            'thumbnail' => $card['artwork'],
            'description' => $card['description'],
            'publisher' => $card['publisher'],
        ];
    }
}
