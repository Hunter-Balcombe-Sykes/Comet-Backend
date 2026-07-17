<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ApplyMenuScanRequest;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\MenuItemDeepLinks;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dashboard surface for a user's menu (the relational site.menus +
// menu_categories + menu_items) plus the per-item order links computed at
// read time from the live online-ordering entries. Most menu CONTENT is owned
// by MenuFetchJob (auto-scraped on online-ordering connect) — this controller
// never scrapes inline. POST /refresh re-dispatches the job (forced).
// POST /scan/apply is the one write path this controller DOES own directly:
// it applies AI-extracted items from a user-uploaded menu photo/PDF via
// MenuScanApplier, independent of any scrape. The menu itself IS also served
// publicly — see PublicMenuController — this controller's endpoints are just
// the authenticated dashboard read/write surface.
class MenuController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly MenuSource $source,
        private readonly MenuScanApplier $scanApplier,
    ) {}

    // GET /api/platforms/menu/status — drives the integrations index card.
    public function status(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $menu = Menu::query()->where('user_id', $user->id)->first();

        // A menu is valid while it has a backing Uber Eats / DoorDash ordering
        // link OR its own scan-sourced content (which never depended on one —
        // see MenuScanApplier). Otherwise it's an orphaned scraped row whose
        // links were removed via a path that didn't clear the menu — guard
        // against that reading as connected when refresh() can't re-scrape it.
        if ($this->source->resolveAll($user) === null && ! $this->hasScanContent($menu)) {
            return $this->success(['connected' => false, 'itemCount' => 0, 'source' => null, 'fetchStatus' => null]);
        }

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

        // Same orphan guard as status(): a menu with scan-sourced content is
        // never orphaned by a missing ordering link — don't null it out.
        if ($this->source->resolveAll($user) === null && ! $this->hasScanContent($menu)) {
            $menu = null;
        }

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
            'diningModes' => $menu?->dining_modes,
            'categories' => $this->categories($menu),
            'links' => $this->source->links($user),
        ]);
    }

    // POST /api/platforms/menu/refresh — re-scrape (forced).
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): Menu is a food-business feature.
        // GET status()/show() stay open (UI-hidden, not endpoint-blocked) — only
        // this mutating path is gated.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            return $this->error('Menu is not available for your account.', 403);
        }

        // Only meaningful when the user has a resolvable Uber Eats / DoorDash link.
        if ($this->source->resolveAll($user) === null) {
            return $this->error('Connect Uber Eats or DoorDash in Online ordering first.', 422);
        }

        // Flip to pending immediately for instant UI feedback; the job also sets it.
        Menu::query()->where('user_id', $user->id)->update(['fetch_status' => 'pending']);

        MenuFetchJob::dispatch((string) $user->id, true);

        return $this->success(['fetchStatus' => 'pending']);
    }

    // POST /api/platforms/menu/scan/apply — apply AI-extracted items from a
    // user-uploaded menu photo/PDF scan (FE10's contract). Never touches the
    // scraper; MenuScanApplier matches by name and merges. Works even for a
    // user with no Uber Eats/DoorDash link at all (creates the menu row).
    public function applyScan(ApplyMenuScanRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15) — same rule as refresh() above.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            return $this->error('Menu is not available for your account.', 403);
        }

        $result = $this->scanApplier->apply($user, $request->validated()['items']);

        return $this->success($result);
    }

    /**
     * Whether $menu carries at least one scan-sourced category — such a menu
     * never depends on a live ordering link (see the orphan guards above).
     */
    private function hasScanContent(?Menu $menu): bool
    {
        if ($menu === null) {
            return false;
        }

        return $menu->relationLoaded('categories')
            ? $menu->categories->contains(fn (MenuCategory $c) => $c->source_platform === 'scan')
            : MenuCategory::query()->where('menu_id', $menu->id)->where('source_platform', 'scan')->exists();
    }

    private function menuFor(User $user): ?Menu
    {
        return Menu::query()
            ->where('user_id', $user->id)
            ->with([
                'categories' => fn ($q) => $q->orderBy('position'),
                'categories.items' => fn ($q) => $q->orderBy('position'),
                'categories.items.platformLinks',
                // Menu-level store links — the base each item's deep link derives from.
                'platformLinks',
            ])
            ->first();
    }

    /**
     * Categories → items shaped for the dashboard grid. Each item carries its
     * representative base price, the aggregate pickup/delivery prices (each
     * tagged with the platform backing the min), the per-platform availability
     * list (platform + price + supported modes + order url), and the DoorDash
     * rating + badges.
     *
     * @return list<array{name:string, items:list<array<string,mixed>>}>
     */
    private function categories(?Menu $menu): array
    {
        if ($menu === null) {
            return [];
        }

        // slug => normalized store_url — base for each item's per-platform deep link.
        $storeUrls = $menu->platformLinks->pluck('store_url', 'platform')->all();

        return $menu->categories->map(fn ($category) => [
            'name' => $category->name,
            'items' => $category->items->map(fn (MenuItem $item) => [
                // Stable persisted id (G6-1/B6, fix-round) — mirrors
                // PublicMenuController's `id` field exactly. This is the
                // endpoint Partna-Frontend's menu-item-detail URLs actually
                // read (authedJsonRequest('/platforms/menu')), so the id
                // needs to live here, not only on the public sitepage
                // payload. Additive field; every existing consumer reading
                // by key is unaffected.
                'id' => (string) $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'image' => $item->image_url,
                // Full image set, hero first — mirrors PublicMenuController's `images`.
                'images' => $item->images,
                'rating' => $item->rating,
                'ratingCount' => $item->rating_count,
                'badges' => $item->badges,
                'basePrice' => $item->base_price,
                'pickupPrice' => $item->pickup_price,
                'pickupSource' => $item->pickup_source,
                'deliveryPrice' => $item->delivery_price,
                'deliverySource' => $item->delivery_source,
                'currency' => $item->currency,
                'platforms' => $this->platforms($item),
                // Per-item deep links ({doordash?: url}) — mirrors PublicMenuController
                // exactly; null when nothing item-level is derivable (see MenuItemDeepLinks).
                'links' => MenuItemDeepLinks::forItem($item->dd_external_id, $storeUrls) ?: null,
            ])->all(),
        ])->all();
    }

    /**
     * The item's per-platform availability list — one entry per ordering platform
     * the dish is on, each with its pickup price + url and delivery price + url (a
     * mode the store doesn't offer is null on both). Empty when the dish has no
     * platform rows.
     *
     * @return list<array{platform:string, pickupPrice:float|null, pickupUrl:string|null, deliveryPrice:float|null, deliveryUrl:string|null}>
     */
    private function platforms(MenuItem $item): array
    {
        return $item->platformLinks->map(fn (MenuItemPlatform $p) => [
            'platform' => $p->platform,
            'pickupPrice' => $this->numberOrNull($p->pickup_price),
            'pickupUrl' => $this->textOrNull($p->pickup_url),
            'deliveryPrice' => $this->numberOrNull($p->delivery_price),
            'deliveryUrl' => $this->textOrNull($p->delivery_url),
        ])->values()->all();
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
