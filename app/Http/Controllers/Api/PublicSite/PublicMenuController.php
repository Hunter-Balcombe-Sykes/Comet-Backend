<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\ContentPopularityReader;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/public/profiles/{handle}/menu
 *
 * Public, unauthenticated — consumed by the Astro sitepage to render a
 * business profile's food menu (categories + items scraped from UberEats /
 * DoorDash). Returns 404 when no fetched menu exists for the handle.
 */
class PublicMenuController extends ApiController
{
    public function __construct(
        private readonly ContentPopularityReader $popularity,
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

        // The owner can hide the menu from their sitepage via the Google
        // Business display toggles (display_settings.menu === false) — the
        // menu is GB-sourced, so the gate lives with that connection.
        $gbDisplay = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', 'google-business')
            ->where('is_active', true)
            ->first(['display_settings'])
            ?->display_settings;
        if ((($gbDisplay['menu'] ?? true) === false)) {
            return $this->error('Not found.', 404);
        }

        $menu = Menu::with([
            'categories' => fn ($q) => $q->with([
                'items' => fn ($q2) => $q2->orderBy('position')->with('platformLinks'),
            ]),
        ])
            ->where('user_id', $userId)
            ->whereNotNull('last_fetched_at')
            ->first();

        if (! $menu) {
            return $this->error('Not found.', 404);
        }

        $currency = $menu->currency ?? 'AUD';

        // Popularity ranks — nullable annotations, inert until ONE consumes them.
        // menu_category keyed by category id, menu_item by item id. Item/category
        // ORDER stays position-sorted; ranks are additive metadata only.
        $siteId = Site::query()->where('user_id', $userId)->value('id');
        $ranks = $this->popularity->forSite($siteId);
        $categoryRanks = $ranks['menu_category'] ?? [];
        $itemRanks = $ranks['menu_item'] ?? [];

        $categories = $menu->categories
            ->map(fn ($cat) => [
                'name' => $cat->name,
                'popularityRank' => $categoryRanks[(string) $cat->id] ?? null,
                'items' => $cat->items->map(fn ($item) => [
                    // Stable persisted id (G6-1/B6) — the frontend keys item-detail
                    // URLs off this instead of the positional {categoryIndex}:{itemIndex}
                    // scheme, which silently pointed at the wrong dish (or 404'd) once a
                    // Refresh reshuffled category/item order. Additive field; every
                    // existing consumer reading by key is unaffected.
                    'id' => (string) $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'imageUrl' => $item->image_url,
                    // base_price stored in dollars as a float; format to 2dp.
                    'price' => $item->base_price !== null
                        ? number_format((float) $item->base_price, 2)
                        : null,
                    // Aggregate per-mode prices (min across platforms) — same 2dp
                    // string-or-null formatting as `price` above.
                    'pickupPrice' => $item->pickup_price !== null
                        ? number_format((float) $item->pickup_price, 2)
                        : null,
                    'deliveryPrice' => $item->delivery_price !== null
                        ? number_format((float) $item->delivery_price, 2)
                        : null,
                    // Uber-Eats-only (ISO 4217, e.g. 'AUD'); null for DoorDash-only dishes.
                    'currency' => $item->currency,
                    // DoorDash-sourced (null for Uber Eats): 👍 percent + count and
                    // the "#N Most liked" badges, rendered on the sitepage menu cards.
                    'rating' => $item->rating,
                    'ratingCount' => $item->rating_count,
                    'badges' => $item->badges,
                    // Per-platform order links — mirrors MenuController::platforms()'s
                    // exact field semantics for the dashboard equivalent. Always an
                    // array (never omitted) — a scan-sourced item has zero rows here.
                    'platforms' => $item->platformLinks->map(fn (MenuItemPlatform $p) => [
                        'platform' => $p->platform,
                        'pickupUrl' => $this->textOrNull($p->pickup_url),
                        'deliveryUrl' => $this->textOrNull($p->delivery_url),
                        'pickupPrice' => $this->numberOrNull($p->pickup_price),
                        'deliveryPrice' => $this->numberOrNull($p->delivery_price),
                    ])->values()->toArray(),
                    'popularityRank' => $itemRanks[(string) $item->id] ?? null,
                ])->values()->toArray(),
            ])
            ->filter(fn ($cat) => count($cat['items']) > 0)
            ->values()
            ->toArray();

        return $this->success([
            'data' => [
                'storeName' => $menu->store_name,
                'currency' => $currency,
                'categories' => $categories,
            ],
        ]);
    }

    /** Mirrors MenuController::numberOrNull() — kept local, duplication over a shared dependency. */
    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** Mirrors MenuController::textOrNull() — kept local, duplication over a shared dependency. */
    private function textOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
