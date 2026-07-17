<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\User\User;

// Composes the authenticated dashboard menu payload (store fields + categories +
// computed order links) from a user's relational menu. Extracted from
// MenuController so the manual-content write endpoints (MenuContentController)
// return the EXACT same shape the dashboard reads after a write, without
// duplicating the composition. Read-only — never mutates the menu.
class MenuPayloadComposer
{
    public function __construct(
        private readonly MenuSource $source,
    ) {}

    /**
     * The full dashboard menu payload for a user, resolving + orphan-guarding the
     * menu the same way GET /platforms/menu does. Use after a write to echo the
     * fresh menu the dashboard will render.
     *
     * @return array<string, mixed>
     */
    public function dashboardPayload(User $user): array
    {
        $menu = $this->load($user);

        // Orphan guard: a scraped menu with no backing Uber Eats / DoorDash link
        // AND no owner-authored (scan/manual) content is stale — hide it (refresh()
        // can't re-scrape it anyway). Owner content never depends on an ordering link.
        if ($this->source->resolveAll($user) === null && ! $this->hasOwnerContent($menu)) {
            $menu = null;
        }

        return $this->compose($user, $menu);
    }

    /** Eager-load a user's menu in the read shape (position order + platform links). */
    public function load(User $user): ?Menu
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
     * Whether $menu carries owner-authored content — a scan/manual category OR any
     * is_manual dish (a scraped category kept alive purely by a manual dish). Such
     * a menu never depends on a live ordering link, so it's never orphaned.
     */
    public function hasOwnerContent(?Menu $menu): bool
    {
        if ($menu === null) {
            return false;
        }

        if ($menu->relationLoaded('categories')) {
            return $menu->categories->contains(function (MenuCategory $c) {
                return in_array($c->source_platform, ['scan', 'manual'], true)
                    || ($c->relationLoaded('items') && $c->items->contains(fn (MenuItem $i) => $i->is_manual));
            });
        }

        return MenuCategory::query()->where('menu_id', $menu->id)
            ->whereIn('source_platform', ['scan', 'manual'])->exists()
            || MenuItem::query()->where('menu_id', $menu->id)->where('is_manual', true)->exists();
    }

    /**
     * The full menu + computed order links, for an already-resolved menu (or null).
     * Mirrors GET /platforms/menu exactly.
     *
     * @return array<string, mixed>
     */
    public function compose(User $user, ?Menu $menu): array
    {
        return [
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
        ];
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
                // Stable persisted id — mirrors PublicMenuController's `id` field.
                // Partna-Frontend's menu-item-detail URLs read THIS endpoint's id.
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
