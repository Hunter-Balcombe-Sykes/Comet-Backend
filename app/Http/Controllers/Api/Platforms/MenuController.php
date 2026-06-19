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
        $menu = Menu::query()->where('user_id', $this->currentUser($request)->id)->first();
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
        $menu = $this->menuFor($user);

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
     * base price plus the per-mode prices (each tagged with its source platform),
     * the DoorDash rating + badges, and Uber Eats modifiers for the expanded card.
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
            ])->all(),
        ])->all();
    }
}
