<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Booksy booking card: listing URL + JSON-LD business identity with the
 * live rating and review count.
 *
 * `$this->resource` is the selection ARRAY.
 */
class BooksyConnectionResource extends ApiResource
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
            'reviewCount' => $this->resource['reviewCount'] ?? null,
            'address' => $this->resource['address'] ?? null,
        ];
    }
}
