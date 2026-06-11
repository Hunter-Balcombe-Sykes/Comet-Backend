<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Google Business map card: the Maps link plus the place name and
 * coordinates parsed from it (no keyless API exposes ratings/reviews).
 *
 * `$this->resource` is the selection ARRAY.
 */
class GoogleBusinessConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'lat' => $this->resource['lat'] ?? null,
            'lng' => $this->resource['lng'] ?? null,
        ];
    }
}
