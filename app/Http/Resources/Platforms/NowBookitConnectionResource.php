<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * NowBookit reservations: the booking link, the venue's accountid + venueid, a
 * best-effort display name, and the keyless booking-widget embed URL (the iframe
 * the dashboard renders).
 *
 * `$this->resource` is the selection ARRAY.
 */
class NowBookitConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'accountId' => $this->resource['accountId'] ?? null,
            'venueId' => $this->resource['venueId'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'embedUrl' => $this->resource['embedUrl'] ?? null,
        ];
    }
}
