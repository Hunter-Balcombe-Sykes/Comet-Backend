<?php

namespace App\Http\Resources\Platforms;

/**
 * Apple Music selection. Flat fields expose `releaseDate` (the podcast sibling
 * exposes `description` instead); the latest tile + highlights tail comes from
 * TileConnectionResource.
 */
class AppleMusicConnectionResource extends TileConnectionResource
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
            'releaseDate' => $this->resource['releaseDate'] ?? null,
            'link' => $this->resource['link'] ?? null,
        ];
    }
}
