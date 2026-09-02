<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/public/profiles/{handle}/integrations
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
 * Slice 5b Task 8 (2026-08-13): there are NO sub-reads left. The shop block —
 * a site lookup, a rememberLocked popularity read (CCG-102) and a
 * ShopContentReader::brandMap() — went with the retired shop keys. Ranks and
 * store cards are PoolResolver's job now, on the profile payload, and it
 * carries its own copy of the same single-flight cache.
 */
class PublicIntegrationController extends ApiController
{
    public function show(string $handle): JsonResponse
    {
        $handleLc = strtolower(trim($handle));
        if ($handleLc === '') {
            return $this->error('Not found.', 404);
        }

        $user = User::query()->where('handle_lc', $handleLc)->first(['id', 'status']);
        if (! $user) {
            return $this->error('Not found.', 404);
        }

        // The publish gate — same predicate as IndividualProfileController::show.
        // The two public read paths must agree, or the gate is half a gate: this
        // one hands back Instagram and Google Business payloads for a site the
        // owner has switched off. Unclaimed builds are exempt (the pre-claim
        // demo); a site-less user has no publish knob to honour.
        // Uncached endpoint, so this is a direct read, not a cached verdict.
        $site = Site::query()->where('user_id', $user->id)->first(['is_published']);
        if ($site !== null && ! $site->is_published && ! $user->isUnclaimed()) {
            return $this->error('Not found.', 404);
        }

        $userId = $user->id;

        // Grouped by platform → list of {resourceId, payload, lastRefreshedAt}.
        // Most platforms have one connection; Shopify can have up to five brands.
        // The payload is allowlisted per platform by the Resource — internal keys
        // (e.g. Instagram's `_folder`) never reach this public, CDN-cached wire.
        $connections = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->active()
            // A.3 (proof catch 2026-09-03): pre-scrape rows are hidden until
            // accepted — this public wire must not describe them.
            ->visible()
            // Booking/reservations LEFT this exclusion list on 2026-07-25 (link
            // classification consolidation). Under Decision 10 every non-Fresha/
            // Square booking brand and every non-OpenTable/ResDiary/NowBookit
            // reservation brand lands on these two SHARED keys, so a Booksy or
            // Resy link is a real public "Book with {provider}" card now. Their
            // PublicIntegrationConnectionResource allowlists were widened to
            // ['url','provider'] in the same change and are the actual exposure
            // gate — excluding the rows here would have made that widening
            // silently inert. Exactly the move online-ordering made in the
            // 2026-07-23 actions rebuild, for the same reason.
            ->orderBy('platform')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            // The `shopBrands.products` eager load is GONE (slice 5a Task 8),
            // and so is everything that replaced it (slice 5b Task 8) — a shop
            // row publishes an empty payload, so any brand/product read here
            // would cost queries per public profile read and feed nothing.
            // display_settings rides along so the Resource can suppress
            // toggled-off sections (reviews/hours/photos/…) from the payload.
            // resource_kind is still selected: the events-slug annotation that
            // needed it is gone, but IntegrationConnection's own accessors and
            // the Resource's standalone-row handling read it.
            ->get(['id', 'platform', 'resource_id', 'resource_kind', 'payload', 'display_settings', 'last_refreshed_at'])
            ->groupBy('platform');

        // Instagram (2026-08-05): the auto switch lives on the connection's
        // own display_settings (auto_sync_latest — the one toggle grammar),
        // which already rides the rows fetched above, so the shared
        // DisplaySettingsFilter suppresses the gallery keys with no in-memory
        // override. The old sites.content_instagram_auto_enabled column and
        // its translation block are gone.

        // The shop block that used to live here is GONE (slice 5b, Task 8,
        // 2026-08-13). It resolved the site's global shop_link_mode, read
        // shop-product popularity ranks through a rememberLocked wrapper and
        // built a whole ShopContentReader::brandMap() to thread into the
        // Resource. `shop` now carries an EMPTY public allowlist, so every one
        // of those reads would be discarded by the Resource while still costing
        // a site lookup, a cache round-trip and brandMap()'s handful of
        // Postgres queries on each public profile read. Products reach the wire
        // through `profile.pools.shop`, and PoolResolver serves their variants,
        // store cards, popularity rank and outbound URL.

        // The event-slug annotation block that used to live here is GONE
        // (slice 2, Task 9, 2026-08-12). It read site.item_slugs and stamped
        // slug/aliases onto every eventbrite/humanitix/events-custom payload;
        // those three platforms now carry an EMPTY public allowlist, so the
        // work would have been discarded by the Resource while still costing
        // two Postgres queries on every public profile read. Events reach the
        // wire through `profile.pools.events`, and PoolResolver serves their
        // slug/aliases from content.item_slugs.

        $platforms = $connections
            ->map(fn ($rows) => PublicIntegrationConnectionResource::collection($rows->values())->resolve())
            ->toArray();

        return $this->success(['data' => ['platforms' => $platforms]]);
    }
}
