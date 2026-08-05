<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Vimeo profile card: canonical page URL + Simple-API profile, the latest
 * uploads (each with a public player embed URL), and the user-curated
 * the latest tile.
 *
 * `$this->resource` is the selection ARRAY.
 */
class VimeoConnectionResource extends ApiResource
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
