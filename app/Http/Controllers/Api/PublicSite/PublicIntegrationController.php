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
use App\Services\Platforms\EventSlugSync;
use App\Services\Platforms\Registry\Platform;
use App\Services\Site\ItemSlugAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
 * The connections list itself is NOT backend-cached: the Cloudflare edge cache
 * does the heavy lifting and is purged on every write by
 * IntegrationConnectionObserver, so caching it would only re-introduce the
 * staleness we just fixed. The query is a single indexed lookup
 * (idx_platform_connections_user_platform_sort).
 *
 * CCG-102 is the one exception: the popularity-rank sub-read IS wrapped in
 * rememberLocked, because it recomputes on a 15-minute cadence rather than
 * changing on write, so edge purging does nothing to bound its cost.
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
        private readonly ItemSlugAllocator $slugs,
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
            // Booking/reservations are still dashboard-only categories (the
            // Resource also strips their payload to {} — empty allowlist —
            // but excluding them here keeps the rows off the wire entirely).
            // online-ordering was in this list too until the 2026-07-23 actions
            // rebuild — its entries now feed the public ordering:<id> actions
            // (SiteActionsService::pool()), so they must reach the wire; the
            // Resource's allowlist for it is the actual exposure gate now.
            ->whereNotIn('platform', [Platform::Booking->value, Platform::Reservations->value])
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
            // resource_kind rides along so annotateEventSlugs() below can tell a
            // standalone/custom event row (payload IS the event) apart from an
            // account row (payload.upcoming[] is the event list) — same
            // resource_kind === 'event' check EventsPlatformController uses.
            ->get(['id', 'platform', 'resource_id', 'resource_kind', 'payload', 'display_settings', 'last_refreshed_at'])
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

        $this->annotateEventSlugs($connections, $userId);

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

    /**
     * Mutate every eventbrite/humanitix/events-custom row's payload in place
     * (before the Resource resolves it) to carry `slug` + `aliases` per event
     * — account rows' upcoming[]/next event objects, or the standalone row's
     * own top level. Best-effort: a lookup failure leaves every event
     * annotated with `slug: null, aliases: [id]` (today's raw-id behaviour)
     * rather than breaking the platforms response.
     */
    /**
     * $connections is the result of an Eloquent Collection's ->groupBy() —
     * groupBy's `new static($groups)` construction means it (and every
     * per-platform sub-collection) stays an Eloquent Collection, not a plain
     * Support Collection, so ->only() here would resolve to Eloquent
     * Collection's PRIMARY-KEY-based override (getKey() on each "key") and
     * blow up on plain platform-name strings. ->get($platform) per platform
     * sidesteps that — get() isn't one of Eloquent Collection's overridden,
     * model-key-aware methods.
     *
     * @param  Collection<string, Collection<int, IntegrationConnection>>  $connections
     */
    private function annotateEventSlugs(Collection $connections, string $userId): void
    {
        $eventRows = collect(EventSlugSync::PLATFORMS)
            ->flatMap(fn (string $platform) => $connections->get($platform) ?? []);
        if ($eventRows->isEmpty()) {
            return;
        }

        $hexIds = $eventRows
            ->flatMap(fn (IntegrationConnection $row) => EventSlugSync::extractEvents($row->resource_kind, $row->payload))
            ->filter(fn ($e) => is_array($e) && is_string($e['id'] ?? null))
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        $slugMap = [];
        if ($hexIds !== []) {
            try {
                $slugMap = $this->slugs->lookupCurrent($userId, ItemSlugAllocator::TYPE_EVENT, $hexIds);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('PublicIntegrationController event-slug lookup failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
            }
        }

        foreach ($eventRows as $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $row->payload = $this->annotateEventPayload($row->resource_kind, $payload, $slugMap);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string, array{slug: string, aliases: list<string>}>  $slugMap  keyed by event hex id
     * @return array<string,mixed>
     */
    private function annotateEventPayload(?string $resourceKind, array $payload, array $slugMap): array
    {
        $annotate = function (array $event) use ($slugMap): array {
            $id = $event['id'] ?? null;
            if (is_string($id) && isset($slugMap[$id])) {
                $event['slug'] = $slugMap[$id]['slug'];
                $event['aliases'] = $slugMap[$id]['aliases'];
            } else {
                $event['slug'] = null;
                $event['aliases'] = is_string($id) ? [$id] : [];
            }

            return $event;
        };

        if ($resourceKind === 'event') {
            return $annotate($payload);
        }

        if (is_array($payload['upcoming'] ?? null)) {
            $payload['upcoming'] = array_map(fn ($e) => is_array($e) ? $annotate($e) : $e, $payload['upcoming']);
        }
        if (is_array($payload['next'] ?? null)) {
            $payload['next'] = $annotate($payload['next']);
        }

        return $payload;
    }
}
