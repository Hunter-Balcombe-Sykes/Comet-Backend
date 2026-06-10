<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Humanitix selection: host page url + organiser name, the next event, and
 * the upcoming list — same top-level contract as Eventbrite so the dashboard
 * + sitepage render both identically.
 *
 * `$this->resource` is the selection ARRAY.
 */
class HumanitixConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'organiser' => $this->resource['organiser'] ?? null,
            'next' => $this->resource['next'] ?? null,
            'upcoming' => $this->resource['upcoming'] ?? [],
        ];
    }
}
