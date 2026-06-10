<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Bandcamp selection: artist origin + name, the latest release tile (flat
 * back-compat fields mirror Apple Music), and curated highlight releases.
 *
 * `$this->resource` is the selection ARRAY.
 */
class BandcampConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'artist' => $this->resource['artist'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'thumbnail' => $this->resource['thumbnail'] ?? null,
            'link' => $this->resource['link'] ?? null,
            'latest' => $this->resource['latest'] ?? null,
            'highlights' => $this->resource['highlights'] ?? [],
        ];
    }
}
