<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * YouTube Music artist card: canonical music.youtube.com profile URL, the
 * latest releases (each linking into YouTube Music with a standard YouTube
 * embed).
 *
 * `$this->resource` is the selection ARRAY. `channelId` is the private
 * re-fetch input and is deliberately not emitted.
 */
class YoutubeMusicConnectionResource extends ApiResource
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
            'link' => $this->resource['link'] ?? null,
            'latest' => $this->resource['latest'] ?? null,
            'items' => $this->resource['items'] ?? null,
        ];
    }
}
