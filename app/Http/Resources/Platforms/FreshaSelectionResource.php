<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Fresha saved selection: the connected store url + name, the chosen team
 * member ("you"), the service menu, and the curated hidden-service ids.
 * `employee` and `services[]` are scraped objects passed through verbatim
 * (their inner keys come straight from Fresha's __NEXT_DATA__ / booking
 * GraphQL — re-allowlisting them would risk dropping fields). This Resource
 * allowlists only the top-level selection keys.
 *
 * `$this->resource` is the inner selection ARRAY (the controller already
 * unwrapped the stored `{url, selection}` envelope before wrapping).
 */
class FreshaSelectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'storeName' => $this->resource['storeName'] ?? null,
            // 'employee' | 'storewide' — storewide (Business accounts) has employee = null.
            'mode' => $this->resource['mode'] ?? 'employee',
            'employee' => $this->resource['employee'] ?? null,
            'services' => $this->resource['services'] ?? [],
            'hiddenServiceIds' => $this->resource['hiddenServiceIds'] ?? [],
        ];
    }
}
