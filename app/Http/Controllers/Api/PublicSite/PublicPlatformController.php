<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\PlatformConnection;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/public/profiles/{handle}/platforms
 *
 * Public, unauthenticated — consumed by the Astro sitepage to render a user's
 * platform sections (Shopify products, Apple releases, Instagram grid, ...).
 *
 * Returns the handle's active platform connections grouped by platform. 404 for
 * an unknown handle (no existence leak — the public 404 standard).
 *
 * Deliberately SEPARATE from the profile payload (IndividualProfileController) —
 * platforms is an additive, self-contained feature.
 *
 * No backend Redis cache here: the Cloudflare edge cache does the heavy lifting
 * and is purged on every write by PlatformConnectionObserver, so a backend
 * cache would only re-introduce the staleness we just fixed. The query is a
 * single indexed lookup (idx_platform_connections_user_platform_sort).
 */
class PublicPlatformController extends ApiController
{
    public function show(string $handle): JsonResponse
    {
        $handleLc = strtolower(trim($handle));
        if ($handleLc === '') {
            return $this->error('Not found.', 404);
        }

        $userId = User::query()->where('handle_lc', $handleLc)->value('id');
        if (! $userId) {
            return $this->error('Not found.', 404);
        }

        // Grouped by platform → list of {resourceId, payload, lastRefreshedAt}.
        // Most platforms have one connection; Shopify can have up to five brands.
        $platforms = PlatformConnection::query()
            ->where('user_id', $userId)
            ->active()
            ->orderBy('platform')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get(['platform', 'resource_id', 'payload', 'last_refreshed_at'])
            ->groupBy('platform')
            ->map(fn ($rows) => $rows->map(fn (PlatformConnection $r) => [
                'resourceId' => $r->resource_id,
                'payload' => $r->payload,
                'lastRefreshedAt' => $r->last_refreshed_at?->toIso8601String(),
            ])->values())
            ->toArray();

        return $this->success(['data' => ['platforms' => $platforms]]);
    }
}
