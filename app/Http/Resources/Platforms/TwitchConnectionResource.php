<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Twitch channel card: canonical channel URL + og-scraped display name,
 * avatar, and bio. The live player embed is built sitepage-side (the iframe
 * parent param must equal the rendering hostname).
 *
 * `$this->resource` is the selection ARRAY.
 */
class TwitchConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'login' => $this->resource['login'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'image' => $this->resource['image'] ?? null,
            'description' => $this->resource['description'] ?? null,
        ];
    }
}
