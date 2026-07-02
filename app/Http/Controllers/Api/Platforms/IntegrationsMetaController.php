<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /integrations/meta — sync metadata for every platform the user has
 * connected, in one query. The dashboard index merges this into each card's
 * status payload to render "Synced 2h ago" / sync-error badges without
 * changing any per-platform endpoint.
 *
 * Response: { platforms: { [platform]: { is_active, last_refreshed_at,
 * last_refresh_status, has_refresh_error } } } — one entry per platform,
 * keeping the most recently refreshed row when a platform holds several
 * connections (shop stores, custom links, ...). last_refresh_error text is
 * deliberately NOT exposed — it can carry internal scraper detail.
 */
class IntegrationsMetaController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $rows = IntegrationConnection::query()
            ->where('user_id', $professional->id)
            ->orderByRaw('last_refreshed_at DESC NULLS LAST')
            ->get(['platform', 'is_active', 'last_refreshed_at', 'last_refresh_status']);

        $platforms = [];
        foreach ($rows as $row) {
            $platform = (string) $row->platform;
            if (isset($platforms[$platform])) {
                continue; // rows are newest-refresh first — keep the first per platform
            }

            $platforms[$platform] = [
                'is_active' => (bool) $row->is_active,
                'last_refreshed_at' => $row->last_refreshed_at?->toIso8601String(),
                'last_refresh_status' => $row->last_refresh_status,
                'has_refresh_error' => $row->last_refresh_status === 'error',
            ];
        }

        return $this->success(['platforms' => $platforms]);
    }
}
