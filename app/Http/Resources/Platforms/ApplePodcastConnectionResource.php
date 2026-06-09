<?php

namespace App\Http\Resources\Platforms;

/**
 * Apple Podcasts selection. Flat fields expose `description` (the music sibling
 * exposes `releaseDate` instead); the latest tile + highlights tail comes from
 * TileConnectionResource.
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
            'link' => $this->resource['link'] ?? null,
        ];
    }
}
