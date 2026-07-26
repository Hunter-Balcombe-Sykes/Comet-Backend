<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Apple Podcasts scraper — resolves a podcast URL or name to recent episodes
// via the public iTunes Search API. No auth needed.
//
// Input: Apple Podcasts show URL (podcasts.apple.com/../id<id>) or show name.
// Fetches the collection ID via search, then looks up episodes. The response
// includes the collection itself as the first result — filtered out by checking
// wrapperType === 'podcastEpisode'.
// Replaces AppleSearch::fetchEpisodes().
class ApplePodcastsScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://itunes.apple.com';
    protected string $authType = 'none';

    /**
     * Fetch recent episodes for an Apple Podcasts show.
     *
     * @return array{items: list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:mixed, format:string}>}>, profile: array{display_name:?string, profile_pic_url:?string}}
     */
    public function fetch(string $identifier): array
    {
        $podcastId = $this->resolvePodcastId($identifier);
        if ($podcastId === null) {
            return ['items' => [], 'profile' => []];
        }

        // Fetch limit+1 because the lookup also returns the podcast collection itself
        $limit = 15;
        $data = $this->apiGet('/lookup', [
            'id' => $podcastId,
            'entity' => 'podcastEpisode',
            'limit' => $limit + 1,
        ]);
        if (! $data) {
            return ['items' => [], 'profile' => []];
        }

        $results = data_get($data, 'results', []);
        $items = $this->mapEpisodes($data, $limit);

        // Extract podcast collection info for profile.
        // Guard against empty items (no episodes found) to avoid PHP 8 TypeError.
        $collectionResult = collect($results)->first(fn ($r) => ($r['wrapperType'] ?? null) === 'collection');
        $profile = [
            'display_name' => $collectionResult['collectionName'] ?? (isset($items[0]) ? ($items[0]['values'][5]['value'] ?? null) : null),
            'profile_pic_url' => $collectionResult['artworkUrl600'] ?? $collectionResult['artworkUrl160'] ?? null,
        ];

        return ['items' => $items, 'profile' => $profile];
    }

    /**
     * Resolve a podcast URL or show name to an iTunes collection ID.
     */
    private function resolvePodcastId(string $input): ?int
    {
        // Apple Podcasts URL — extract ID directly
        if (str_starts_with($input, 'http') && str_contains($input, 'podcasts.apple.com')) {
            if (preg_match('~/id(\d+)~', $input, $m)) {
                return (int) $m[1];
            }
        }

        // Search by name
        $data = $this->apiGet('/search', [
            'term' => $input,
            'entity' => 'podcast',
            'limit' => 1,
        ]);
        $id = data_get($data, 'results.0.collectionId');

        return $id ? (int) $id : null;
    }

    /**
     * Map iTunes lookup response to V5 items sorted by release date (newest first).
     *
     * @return list<array>
     */
    private function mapEpisodes(array $data, int $limit): array
    {
        $episodes = collect(data_get($data, 'results', []))
            ->filter(fn ($r) => ($r['wrapperType'] ?? null) === 'podcastEpisode')
            ->sortByDesc(fn ($r) => $r['releaseDate'] ?? '')
            ->take($limit)
            ->values()
            ->all();

        $items = [];
        foreach ($episodes as $ep) {
            $artworkUrl = $ep['artworkUrl600'] ?? $ep['artworkUrl160'] ?? $ep['artworkUrl60'] ?? null;
            $description = $ep['description'] ?? $ep['shortDescription'] ?? '';

            $items[] = [
                'identifier' => (string) ($ep['trackId'] ?? ''),
                'name' => $ep['trackName'] ?? null,
                'item_type' => 'podcast episode',
                'values' => [
                    ['field_name' => 'name', 'value' => $ep['trackName'] ?? null, 'format' => 'text'],
                    ['field_name' => 'artwork_url', 'value' => $this->hdArtwork($artworkUrl), 'format' => 'image'],
                    ['field_name' => 'page_url', 'value' => $ep['trackViewUrl'] ?? null, 'format' => 'url'],
                    ['field_name' => 'description', 'value' => is_string($description) ? $description : '', 'format' => 'text'],
                    ['field_name' => 'release_date', 'value' => $ep['releaseDate'] ?? null, 'format' => 'date'],
                    ['field_name' => 'collection_name', 'value' => $ep['collectionName'] ?? null, 'format' => 'text'],
                ],
            ];
        }

        return $items;
    }

    /**
     * Upgrade artwork URL to 600x600 when possible.
     */
    private function hdArtwork(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        // Already 600 or larger — return as-is
        if (str_contains($url, '600x600')) {
            return $url;
        }
        // Upgrade from smaller size
        return preg_replace('/\d+x\d+bb\.jpg/', '600x600bb.jpg', $url);
    }
}
