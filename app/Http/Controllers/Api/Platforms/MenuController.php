<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Read-only dashboard surface for a user's fetched menu (the relational
// site.menus + menu_categories + menu_items) plus the per-item order links
// computed at read time from the live online-ordering entries. The menu CONTENT
// is owned by MenuFetchJob (auto-scraped on online-ordering connect) — this
// controller never scrapes inline. POST /refresh re-dispatches the job (forced).
// Dashboard-only: the menu is never exposed on the public sitepage.
class MenuController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly MenuSource $source) {}

    // GET /api/platforms/menu/status — drives the integrations index card.
    public function status(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // A menu only exists while a backing Uber Eats / DoorDash ordering link
        // does. Guard against orphaned rows whose links were removed via a path
        // that didn't clear the menu — otherwise a stale menu reads as connected
        // even though refresh() can't re-scrape it (no source).
        if ($this->source->resolveAll($user) === null) {
            return $this->success(['connected' => false, 'itemCount' => 0, 'source' => null, 'fetchStatus' => null]);
        }

        $menu = Menu::query()->where('user_id', $user->id)->first();
        $itemCount = $menu ? MenuItem::query()->where('menu_id', $menu->id)->count() : 0;

        return $this->success([
            'connected' => $itemCount > 0,
            'itemCount' => $itemCount,
            'source' => $menu?->content_source,
            'fetchStatus' => $menu?->fetch_status,
        ]);
    }

    // GET /api/platforms/menu — the full menu + computed order links.
    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        // No backing Uber Eats / DoorDash ordering link → no menu (don't serve an
        // orphaned row whose links were removed without clearing the menu).
        $menu = $this->source->resolveAll($user) === null ? null : $this->menuFor($user);

        return $this->success([
            'source' => $menu?->content_source,
            'storeName' => $menu?->store_name,
            'logo' => $menu?->logo_url,
            'rating' => $menu?->rating,
            'reviewCount' => $menu?->review_count,
            'currency' => $menu?->currency,
            'pickupPlatform' => $menu?->pickup_platform,
            'deliveryPlatform' => $menu?->delivery_platform,
            'fetchStatus' => $menu?->fetch_status,
            'categories' => $this->categories($menu),
            'links' => $this->source->links($user),
        ]);
    }

    // POST /api/platforms/menu/refresh — re-scrape (forced).
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Only meaningful when the user has a resolvable Uber Eats / DoorDash link.
        if ($this->source->resolveAll($user) === null) {
            return $this->error('Connect Uber Eats or DoorDash in Online ordering first.', 422);
        }

        // Flip to pending immediately for instant UI feedback; the job also sets it.
        Menu::query()->where('user_id', $user->id)->update(['fetch_status' => 'pending']);

        MenuFetchJob::dispatch((string) $user->id, true);

        return $this->success(['fetchStatus' => 'pending']);
    }

    private function menuFor(User $user): ?Menu
    {
        return Menu::query()
            ->where('user_id', $user->id)
            ->with([
                'categories' => fn ($q) => $q->orderBy('position'),
                'categories.items' => fn ($q) => $q->orderBy('position'),
            ])
            ->first();
    }

    /**
     * Categories → items shaped for the dashboard grid. Each item carries its
     * representative base price, the aggregate pickup/delivery prices (each
     * tagged with the platform backing the min), the per-platform availability
     * list (platform + price + supported modes + order url), the DoorDash rating
     * + badges, and Uber Eats modifiers for the expanded card.
     *
     * @return list<array{name:string, items:list<array<string,mixed>>}>
     */
    private function categories(?Menu $menu): array
    {
        if ($menu === null) {
            return [];
        }

        return $menu->categories->map(fn ($category) => [
            'name' => $category->name,
            'items' => $category->items->map(fn (MenuItem $item) => [
                'name' => $item->name,
                'description' => $item->description,
                'image' => $item->image_url,
                'modifiers' => $item->modifiers,
                'isSoldOut' => $item->is_sold_out,
                'rating' => $item->rating,
                'ratingCount' => $item->rating_count,
                'badges' => $item->badges,
                'basePrice' => $item->base_price,
                'pickupPrice' => $item->pickup_price,
                'pickupSource' => $item->pickup_source,
                'deliveryPrice' => $item->delivery_price,
                'deliverySource' => $item->delivery_source,
                'platforms' => $this->platforms($item),
            ])->all(),
        ])->all();
    }

    /**
     * The item's per-platform availability list — one entry per ordering platform
     * the dish is on, each with its pickup price + url and delivery price + url (a
     * mode the store doesn't offer is null on both). Empty when a pre-union legacy
     * row has nothing stored.
     *
     * Tolerates rows persisted before the per-mode split (shape {price, modes[],
     * url}) by mapping that single price/url onto whichever modes the row listed,
     * so the modal renders correctly until the next scrape rewrites the row.
     *
     * @return list<array{platform:string, pickupPrice:float|null, pickupUrl:string|null, deliveryPrice:float|null, deliveryUrl:string|null}>
     */
    private function platforms(MenuItem $item): array
    {
        $platforms = is_array($item->platforms) ? $item->platforms : [];

        return array_values(array_map(function (array $p) {
            // Current shape: explicit per-mode price + url.
            if (array_key_exists('pickupUrl', $p) || array_key_exists('deliveryUrl', $p)
                || array_key_exists('pickupPrice', $p) || array_key_exists('deliveryPrice', $p)) {
                return [
                    'platform' => $p['platform'] ?? null,
                    'pickupPrice' => $this->numberOrNull($p['pickupPrice'] ?? null),
                    'pickupUrl' => $this->textOrNull($p['pickupUrl'] ?? null),
                    'deliveryPrice' => $this->numberOrNull($p['deliveryPrice'] ?? null),
                    'deliveryUrl' => $this->textOrNull($p['deliveryUrl'] ?? null),
                ];
            }

            // Legacy shape: single {price, modes[], url} → fan out to the listed modes.
            $modes = (array) ($p['modes'] ?? []);
            $price = $this->numberOrNull($p['price'] ?? null);
            $url = $this->textOrNull($p['url'] ?? null);
            $offersPickup = in_array('pickup', $modes, true);
            $offersDelivery = in_array('delivery', $modes, true);

            return [
                'platform' => $p['platform'] ?? null,
                'pickupPrice' => $offersPickup ? $price : null,
                'pickupUrl' => $offersPickup ? $url : null,
                'deliveryPrice' => $offersDelivery ? $price : null,
                'deliveryUrl' => $offersDelivery ? $url : null,
            ];
        }, array_filter($platforms, fn ($p) => is_array($p) && isset($p['platform']))));
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function textOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
