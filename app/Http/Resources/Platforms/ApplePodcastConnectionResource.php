<?php

namespace App\Http\Resources\Platforms;

/**
 * Apple Podcasts selection. Flat fields expose `description` plus `releaseDate`
 * (the latter for chronological sorting on the sitepage); the latest tile +
 * tail comes from TileConnectionResource.
 */
class ApplePodcastConnectionResource extends TileConnectionResource
{
    /**
     * @return array<string, mixed>
     */
    protected function flatFields(): array
    {
        return [
            'input' => $this->resource['input'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'thumbnail' => $this->resource['thumbnail'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'releaseDate' => $this->resource['releaseDate'] ?? null,
            'link' => $this->resource['link'] ?? null,
        ];
    }
}
