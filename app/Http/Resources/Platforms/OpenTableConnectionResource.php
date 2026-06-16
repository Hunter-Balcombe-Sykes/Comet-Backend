<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * OpenTable reservations: the restaurant link, its numeric id, a best-effort
 * display name, and the keyless reservation-widget embed URL (the iframe the
 * dashboard renders, and the sitepage will later).
 *
 * `$this->resource` is the selection ARRAY.
 */
class OpenTableConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'rid' => $this->resource['rid'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'embedUrl' => $this->resource['embedUrl'] ?? null,
        ];
    }
}
