<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Mixcloud profile card: open-API profile (name / avatar / followers),
 * the profile-feed player embed, and the latest shows.
 *
 * `$this->resource` is the selection ARRAY.
 */
class MixcloudConnectionResource extends ApiResource
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
            'thumbnail' => $this->resource['thumbnail'] ?? null,
            'followers' => $this->resource['followers'] ?? null,
            'embedUrl' => $this->resource['embedUrl'] ?? null,
            'latest' => $this->resource['latest'] ?? null,
            'items' => $this->resource['items'] ?? null,
        ];
    }
}
