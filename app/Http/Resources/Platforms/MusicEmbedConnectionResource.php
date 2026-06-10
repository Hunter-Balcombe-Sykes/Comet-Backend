<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Spotify / SoundCloud music-embed selection: the canonical entity link, the
 * resolved display name + artwork, and the official embed-player URL. Shared
 * by both platforms — same stored shape.
 *
 * `$this->resource` is the selection ARRAY.
 */
class MusicEmbedConnectionResource extends ApiResource
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
            'embedUrl' => $this->resource['embedUrl'] ?? null,
            'link' => $this->resource['link'] ?? null,
        ];
    }
}
