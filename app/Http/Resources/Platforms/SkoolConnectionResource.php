<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Skool community card: canonical community url + og-scraped name, avatar
 * image, and description.
 *
 * `$this->resource` is the selection ARRAY.
 */
class SkoolConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'image' => $this->resource['image'] ?? null,
            'description' => $this->resource['description'] ?? null,
        ];
    }
}
