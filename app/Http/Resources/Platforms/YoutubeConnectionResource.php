<?php

namespace App\Http\Resources\Platforms;

/**
 * YouTube channel selection: flat header fields + the latest-video tile and the
 * Shape/tail defined by TileConnectionResource.
 */
class YoutubeConnectionResource extends TileConnectionResource
{
    /**
     * @return array<string, mixed>
     */
    protected function flatFields(): array
    {
        return [
            'handle' => $this->resource['handle'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'link' => $this->resource['link'] ?? null,
            'thumbnail' => $this->resource['thumbnail'] ?? null,
        ];
    }
}
