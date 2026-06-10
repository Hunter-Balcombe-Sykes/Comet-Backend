<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Strava club card: club URL + og-scraped identity and live member count.
 *
 * `$this->resource` is the selection ARRAY.
 */
class StravaConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'location' => $this->resource['location'] ?? null,
            'image' => $this->resource['image'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'members' => $this->resource['members'] ?? null,
        ];
    }
}
