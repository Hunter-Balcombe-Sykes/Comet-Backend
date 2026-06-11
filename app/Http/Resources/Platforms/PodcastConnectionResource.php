<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Podcast (RSS) card: resolved feed URL + show identity and the latest
 * episodes, each with its public audio enclosure.
 *
 * `$this->resource` is the selection ARRAY.
 */
class PodcastConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'pageUrl' => $this->resource['pageUrl'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'thumbnail' => $this->resource['thumbnail'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'link' => $this->resource['link'] ?? null,
            'latest' => $this->resource['latest'] ?? null,
            'episodes' => $this->resource['episodes'] ?? null,
        ];
    }
}
