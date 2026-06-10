<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Plain external-link platforms (Ticketek tickets, Square + Timely booking
 * links): a validated URL plus an optional user label. No scraping.
 *
 * `$this->resource` is the selection ARRAY.
 */
class ExternalLinkConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'label' => $this->resource['label'] ?? null,
        ];
    }
}
