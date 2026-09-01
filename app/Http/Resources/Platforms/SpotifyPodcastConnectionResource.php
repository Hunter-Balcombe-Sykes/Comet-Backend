<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Spotify Podcasts selection (Item 11f): the canonical show link and the
 * resolved identity card — name, artwork, description, publisher. Not the
 * TileConnectionResource frame: the connect resolves the SHOW, not a latest
 * episode (episodes flow through the ingest lane into the listen pool), so
 * there is no `latest` tail here.
 *
 * `$this->resource` is the selection ARRAY.
 */
class SpotifyPodcastConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'thumbnail' => $this->resource['thumbnail'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'publisher' => $this->resource['publisher'] ?? null,
            'link' => $this->resource['link'] ?? null,
        ];
    }
}
