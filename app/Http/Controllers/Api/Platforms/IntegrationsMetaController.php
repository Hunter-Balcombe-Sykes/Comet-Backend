<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Platforms\IntegrationsMetaResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /platforms/meta — sync metadata for every platform the user has
 * connected, in one query. The dashboard index merges this into each card's
 * status payload to render "Synced 2h ago" / sync-error badges without
 * changing any per-platform endpoint.
 *
 * Response: { platforms: { [platform]: { is_active, last_refreshed_at,
 * last_refresh_status, has_refresh_error } } } — one entry per platform.
 * When a platform holds several connections (shop stores, custom links,
 * multi-account platforms like YouTube/Bandcamp/Vimeo), `is_active` and
 * `has_refresh_error` are aggregated ANY-across-connections (true if any
 * connection qualifies) so an errored second account is never hidden behind
 * a healthy one, and `last_refreshed_at` is the MAX across connections
 * (SEM-1 — this used to just keep an arbitrary first row per platform, which
 * was nondeterministic whenever two connections tied on `last_refreshed_at`,
 * e.g. two never-refreshed rows both sorting into the NULLS-LAST tail).
 * `last_refresh_status` stays a single representative value — the status of
 * the most-recently-refreshed connection, ties broken deterministically by
 * `id`. last_refresh_error text is deliberately NOT exposed — it can carry
 * internal scraper detail.
 */
class IntegrationsMetaController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        // Secondary sort by id makes the "representative row" (used below for
        // last_refresh_status) deterministic even when last_refreshed_at ties —
        // without it, ties depend on scan order, which Postgres does not guarantee.
        $rows = IntegrationConnection::query()
            ->where('user_id', $professional->id)
            ->orderByRaw('last_refreshed_at DESC NULLS LAST')
            ->orderBy('id')
            ->get(['id', 'platform', 'is_active', 'last_refreshed_at', 'last_refresh_status']);

        $platforms = [];
        foreach ($rows->groupBy('platform') as $platform => $group) {
            $platforms[(string) $platform] = [
                'is_active' => $group->contains(fn ($row) => (bool) $row->is_active),
                'last_refreshed_at' => $group->pluck('last_refreshed_at')->max(),
                'last_refresh_status' => $group->first()->last_refresh_status,
                'has_refresh_error' => $group->contains(fn ($row) => $row->last_refresh_status === 'error'),
            ];
        }

        // OV-A: staff-managed availability (core.feature_availability, keys
        // 'integration.<platform>') for EVERY registry platform — connected or
        // not — so the dashboard index can hide/grey unavailable integrations.
        // Absence of rules = available; see FeatureAvailability for the pattern.
        $availabilityMap = FeatureAvailability::for($professional);
        $availability = [];
        foreach (app(PlatformRegistry::class)->keys() as $platformKey) {
            $availability[$platformKey] = $availabilityMap->allows('integration.'.$platformKey);
        }

        $payload = (new IntegrationsMetaResource($platforms))->resolve();
        $payload['availability'] = $availability;

        return $this->success($payload);
    }
}
