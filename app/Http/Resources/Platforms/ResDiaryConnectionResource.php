<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * ResDiary reservations: the restaurant link, its microsite name, a best-effort
 * display name, and the keyless booking-widget embed URL (the iframe the
 * dashboard renders).
 *
 * `$this->resource` is the selection ARRAY.
 */
class ResDiaryConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'microsite' => $this->resource['microsite'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'embedUrl' => $this->resource['embedUrl'] ?? null,
        ];
    }
}
