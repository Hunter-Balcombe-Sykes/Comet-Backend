<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Platforms\Registry\Platform;
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
 * and is purged on every write by IntegrationConnectionObserver, so a backend
 * cache would only re-introduce the staleness we just fixed. The query is a
 * single indexed lookup (idx_platform_connections_user_platform_sort).
 */
class PublicIntegrationController extends ApiController
{
    // CCG-102: matches the analytics:compute-popularity schedule cadence
    // (routes/console.php, everyFifteenMinutes) — ranks only change on that
    // cadence, so staleness beyond it buys nothing and this just bounds it.
    private const POPULARITY_CACHE_TTL_SECONDS = 900;

    public function __construct(
        private readonly ContentPopularityReader $popularity,
        private readonly CacheLockService $cache,
    ) {}

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
        // The payload is allowlisted per platform by the Resource — internal keys
        // (e.g. Instagram's `_folder`) never reach this public, CDN-cached wire.
        $connections = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->active()
            // Dashboard-only categories never reach the public sitepage. The
            // Resource also strips their payload to {} (empty allowlist), but
            // excluding them here keeps the rows off the wire entirely.
            ->whereNotIn('platform', [Platform::Booking->value, Platform::Reservations->value, Platform::OnlineOrdering->value])
            ->orderBy('platform')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            // FOUND-25: shop brands live in the relational child tables now —
            // eager load so PublicIntegrationConnectionResource can build the
            // brand-keyed map without an N+1. `id` is required for the relation
            // to hydrate (it wasn't previously selected).
            ->with(['shopBrands.products'])
            // display_settings rides along so the Resource can suppress
            // toggled-off sections (reviews/hours/photos/…) from the payload.
            ->get(['id', 'platform', 'resource_id', 'payload', 'display_settings', 'last_refreshed_at'])
            ->groupBy('platform');

        // Instagram: the gallery card is UNIFIED with the Content/Media "auto
        // sync" switch — one field (sites.content_instagram_auto_enabled) governs
        // ALL auto IG content. Strip the card payload ONLY when the switch is
        // explicitly OFF (false): reflect it as an in-memory display_settings
        // override (not persisted) so the shared DisplaySettingsFilter drops the
        // gallery keys. null (never set) keeps the card's historical show-by-
        // default — the connect-time seed sets it true for real connections, so
        // this never silently hides a connected user's content.
        if ($connections->has('instagram')) {
            $site = Site::query()->where('user_id', $userId)->first(['content_instagram_auto_enabled']);
            if ($site?->content_instagram_auto_enabled === false) {
                foreach ($connections->get('instagram') as $row) {
                    $ds = (array) ($row->display_settings ?? []);
                    $ds['gallery'] = false;
                    $row->display_settings = $ds;
                }
            }
        }

        // GLOBAL shop link mode (2026-07-08): one site-level choice applied to
        // every connected store. Resolved once (single indexed lookup) and
        // stamped onto each brand's public linkMode by the Resource, so the
        // sitepage keeps reading brand.linkMode per the existing contract.
        // Only fetched when there's actually a shop connection to stamp. The same
        // lookup yields the site id used to read shop-product popularity ranks.
        $shopLinkMode = null;
        $productRanks = [];
        if ($connections->has('shop')) {
            $site = Site::query()->where('user_id', $userId)->first(['id', 'shop_link_mode']);
            $shopLinkMode = $site?->shop_link_mode;
            // shop-product ranks annotate each product with a nullable
            // popularityRank on the public wire (inert until ONE consumes it).
            // CCG-102: single-flight cached (mirrors IndividualProfileController) —
            // this read used to hit Postgres on every request with no cache wrapper.
            $siteId = $site?->id;
            $ranks = $siteId !== null
                ? $this->cache->rememberLocked(
                    CacheKeyGenerator::sitePopularityRanks($siteId),
                    self::POPULARITY_CACHE_TTL_SECONDS,
                    fn () => $this->popularity->forSite($siteId),
                )
                : [];
            $productRanks = $ranks['shop_product'] ?? [];
        }

        $platforms = $connections
            ->map(fn ($rows, $platform) => $platform === 'shop'
                // Thread the globals into each shop connection resource — collection()
                // can't forward the overrides, so map the rows explicitly.
                ? $rows->values()
                    ->map(fn ($row) => (new PublicIntegrationConnectionResource($row))
                        ->withShopLinkMode($shopLinkMode)
                        ->withProductRanks($productRanks)
                        ->resolve())
                    ->all()
                : PublicIntegrationConnectionResource::collection($rows->values())->resolve())
            ->toArray();

        return $this->success(['data' => ['platforms' => $platforms]]);
    }
}
