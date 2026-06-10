<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Calendly booking card: scheduling-page URL + public profile and the
 * bookable session types (each deep-links to its booking page).
 *
 * `$this->resource` is the selection ARRAY.
 */
class CalendlyConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'slug' => $this->resource['slug'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'image' => $this->resource['image'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'eventTypes' => $this->resource['eventTypes'] ?? null,
        ];
    }
}
