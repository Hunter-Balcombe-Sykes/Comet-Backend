<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Pinterest profile card: profile identity (name / avatar / followers)
 * plus the latest pins from the public RSS feed.
 *
 * `$this->resource` is the selection ARRAY.
 */
class PinterestConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'username' => $this->resource['username'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'image' => $this->resource['image'] ?? null,
            'followers' => $this->resource['followers'] ?? null,
            'latest' => $this->resource['latest'] ?? null,
            'items' => $this->resource['items'] ?? null,
        ];
    }
}
