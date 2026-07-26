<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Apple Music scraper — resolves an artist URL or name to their recent
// releases via the public iTunes Search API. No auth needed.
//
// Input: Apple Music artist URL (music.apple.com/../artist/../<id>) or
// artist name. Fetches the artist ID via search, then looks up albums.
// Artwork is upgraded from 100x100 to 600x600 for display quality.
// Replaces AppleSearch::fetchAlbums().
class AppleMusicScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://itunes.apple.com';
    protected string $authType = 'none';

    private const HD_ARTWORK_SIZE = '600x600bb.jpg';

    /**
     * Fetch recent albums/releases for an Apple Music artist.
     *
     * @return list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:mixed, format:string}>}>
     */
    public function fetch(string $identifier): array
    {
        $artistId = $this->resolveArtistId($identifier);
        if ($artistId === null) {
            return [];
        }

        $data = $this->apiGet('/lookup', [
            'id' => $artistId,
            'entity' => 'album',
            'limit' => 25,
        ]);
        if (! $data) {
            return [];
        }

        return $this->mapAlbums($data);
    }

    /**
     * Resolve an artist URL or name to an iTunes artist ID.
     */
    private function resolveArtistId(string $input): ?int
    {
        // Apple Music artist URL — extract ID directly
        if (str_starts_with($input, 'http') && str_contains($input, 'music.apple.com')) {
            if (preg_match('~/artist/[^/]+/(\d+)~', $input, $m)) {
                return (int) $m[1];
            }
        }

        // Search by name
        $data = $this->apiGet('/search', [
            'term' => $input,
            'entity' => 'musicArtist',
            'limit' => 1,
        ]);
        $id = data_get($data, 'results.0.artistId');

        return $id ? (int) $id : null;
    }

    /**
     * Map iTunes lookup response to V5 items sorted by release date (newest first).
     *
     * @return list<array>
     */
    private function mapAlbums(array $data): array
    {
        $albums = collect(data_get($data, 'results', []))
            ->filter(fn ($r) => ($r['wrapperType'] ?? null) === 'collection')
            ->sortByDesc(fn ($r) => $r['releaseDate'] ?? '')
            ->values()
            ->all();

        $items = [];
        foreach ($albums as $album) {
            $items[] = [
                'identifier' => (string) ($album['collectionId'] ?? ''),
                'name' => $album['collectionName'] ?? null,
                'item_type' => 'track',
                'values' => [
                    ['field_name' => 'name', 'value' => $album['collectionName'] ?? null, 'format' => 'text'],
                    ['field_name' => 'artwork_url', 'value' => $this->hdArtwork($album['artworkUrl100'] ?? null), 'format' => 'image'],
                    ['field_name' => 'page_url', 'value' => $album['collectionViewUrl'] ?? null, 'format' => 'url'],
                    ['field_name' => 'release_date', 'value' => $album['releaseDate'] ?? null, 'format' => 'date'],
                    ['field_name' => 'artist_name', 'value' => $album['artistName'] ?? null, 'format' => 'text'],
                    ['field_name' => 'track_count', 'value' => $album['trackCount'] ?? null, 'format' => 'number'],
                ],
            ];
        }

        return $items;
    }

    /**
     * Upgrade artwork URL from 100x100 to 600x600 for display quality.
     */
    private function hdArtwork(?string $url100): ?string
    {
        if ($url100 === null) {
            return null;
        }
        return str_replace('100x100bb.jpg', self::HD_ARTWORK_SIZE, $url100);
    }
}
