<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Read-only dashboard surface for the user's fetched menu (the single site.menus
// row) plus the per-item order links computed at read time from the live
// online-ordering entries. The menu CONTENT is owned by MenuFetchJob (auto
// scraped on online-ordering connect) — this controller never scrapes inline.
// POST /refresh re-dispatches the job (forced). Dashboard-only: the menu is
// never exposed on the public sitepage.
class MenuController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly MenuSource $source) {}

    // GET /api/platforms/menu/status — drives the integrations index card.
    public function status(Request $request): JsonResponse
    {
        $menu = $this->menuFor($this->currentUser($request));
        $itemCount = $this->itemCount($menu);

        return $this->success([
            'connected' => $itemCount > 0,
            'itemCount' => $itemCount,
            'source' => $menu?->source,
            'fetchStatus' => $menu?->fetch_status,
        ]);
    }

    // GET /api/platforms/menu — the full menu + computed order links.
    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $menu = $this->menuFor($user);

        return $this->success([
            'source' => $menu?->source,
            'rating' => $menu?->rating,
            'reviewCount' => $menu?->review_count,
            'currency' => $menu?->currency,
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
        if ($this->source->resolve($user) === null) {
            return $this->error('Connect Uber Eats or DoorDash in Online ordering first.', 422);
        }

        // Flip to pending immediately for instant UI feedback; the job also sets it.
        $menu = $this->menuFor($user);
        $menu?->forceFill(['fetch_status' => 'pending'])->save();

        MenuFetchJob::dispatch((string) $user->id, true);

        return $this->success(['fetchStatus' => 'pending']);
    }

    private function menuFor(User $user): ?Menu
    {
        return Menu::query()->where('user_id', $user->id)->first();
    }

    private function itemCount(?Menu $menu): int
    {
        $total = 0;
        foreach ($this->categories($menu) as $category) {
            $total += is_array($category['items'] ?? null) ? count($category['items']) : 0;
        }

        return $total;
    }

    /** @return list<array<string,mixed>> */
    private function categories(?Menu $menu): array
    {
        return is_array($menu?->categories) ? array_values($menu->categories) : [];
    }
}
