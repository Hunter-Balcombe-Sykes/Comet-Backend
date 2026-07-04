<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Shapes the per-platform sync-status map for GET /platforms/meta.
 *
 * `$this->resource` is a platform-keyed array already aggregated by the
 * controller (SEM-1): `is_active` / `last_refreshed_at` (Carbon|null) /
 * `last_refresh_status` / `has_refresh_error`. This class only relocates the
 * Carbon→ISO8601 formatting out of the controller — no shaping decisions
 * live here.
 */
class IntegrationsMetaResource extends ApiResource
{
    /**
     * @return array{platforms: array<string, array{is_active: bool, last_refreshed_at: ?string, last_refresh_status: ?string, has_refresh_error: bool}>}
     */
    public function toArray(Request $request): array
    {
        $platforms = [];
        foreach ($this->resource as $platform => $meta) {
            $platforms[(string) $platform] = [
                'is_active' => $meta['is_active'],
                'last_refreshed_at' => $meta['last_refreshed_at']?->toIso8601String(),
                'last_refresh_status' => $meta['last_refresh_status'],
                'has_refresh_error' => $meta['has_refresh_error'],
            ];
        }

        return ['platforms' => $platforms];
    }
}
