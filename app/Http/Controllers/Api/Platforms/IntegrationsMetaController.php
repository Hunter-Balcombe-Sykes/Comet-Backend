<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Platforms\IntegrationsMetaResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Live item counts per platform (plan 04 step B, 2026-08-27): how
        // many content items each platform's connections actually feed —
        // through the projection's own chain (connection → content.sources →
        // source_items → item_anchors → items), so the number is the same
        // truth the pools render. One grouped query for the whole user; a
        // platform with connections but no ingested items reads 0, and the
        // sheet says "still importing" instead of nothing. Fail-open: the
        // content lane is absent in some partial test envs.
        $itemCounts = [];
        try {
            $countRows = DB::connection('pgsql')
                ->table('site.platform_connections as ic')
                ->join('content.sources as cs', 'cs.connection_id', '=', 'ic.id')
                ->join('content.source_items as si', function ($join) {
                    $join->on('si.source_id', '=', 'cs.id')->whereNull('si.removed_at');
                })
                ->join('content.item_anchors as ia', function ($join) {
                    $join->on('ia.coord', '=', 'si.coord')->on('ia.user_id', '=', 'ic.user_id');
                })
                ->join('content.items as i', function ($join) {
                    $join->on('i.id', '=', 'ia.item_id')->whereNull('i.removed_at');
                })
                ->where('ic.user_id', $professional->id)
                ->groupBy('ic.platform')
                ->selectRaw('ic.platform, count(distinct i.id) as item_count')
                ->pluck('item_count', 'platform');
            $itemCounts = $countRows->all();
        } catch (\Throwable) {
            $itemCounts = [];
        }

        $platforms = [];
        foreach ($rows->groupBy('platform') as $platform => $group) {
            $platforms[(string) $platform] = [
                'is_active' => $group->contains(fn ($row) => (bool) $row->is_active),
                'last_refreshed_at' => $group->pluck('last_refreshed_at')->max(),
                'last_refresh_status' => $group->first()->last_refresh_status,
                'has_refresh_error' => $group->contains(fn ($row) => $row->last_refresh_status === 'error'),
                'item_count' => (int) ($itemCounts[(string) $platform] ?? 0),
            ];
        }

        // OV-A: availability for EVERY registry platform — connected or not —
        // so the dashboard index can hide/grey integrations this user cannot
        // connect. Two gates fold into the one answer, because the consumer's
        // question is singular ("can I connect this?"): staff-managed
        // availability (core.feature_availability, keys 'integration.<platform>';
        // absence of rules = available) AND the descriptor's requiresCapability
        // predicate (e.g. reservations providers need can_use_reservations).
        // The capability gate was previously enforced only at connect time via
        // IntegrationConnectionPolicy, so the dashboard offered platforms whose
        // connect then 403'd.
        $availabilityMap = FeatureAvailability::for($professional);
        $registry = app(PlatformRegistry::class);
        $availability = [];
        foreach ($registry->keys() as $platformKey) {
            $availability[$platformKey] = $availabilityMap->allows('integration.'.$platformKey)
                && $registry->get($platformKey)->availableFor($professional);
        }

        $payload = (new IntegrationsMetaResource($platforms))->resolve();
        $payload['availability'] = $availability;

        return $this->success($payload);
    }
}
