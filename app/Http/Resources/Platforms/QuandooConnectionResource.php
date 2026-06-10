<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Quandoo reservation card: restaurant page URL + JSON-LD identity with
 * the live rating (out of bestRating — Quandoo scores /6), cuisines, address.
 *
 * `$this->resource` is the selection ARRAY.
 */
class QuandooConnectionResource extends ApiResource
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
            'rating' => $this->resource['rating'] ?? null,
            'bestRating' => $this->resource['bestRating'] ?? null,
            'reviewCount' => $this->resource['reviewCount'] ?? null,
            'cuisines' => $this->resource['cuisines'] ?? null,
            'address' => $this->resource['address'] ?? null,
        ];
    }
}
