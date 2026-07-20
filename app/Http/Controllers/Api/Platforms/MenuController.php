<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ApplyMenuScanRequest;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuItem;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\MenuPayloadComposer;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dashboard surface for a user's menu (the relational site.menus +
// menu_categories + menu_items) plus the per-item order links computed at
// read time from the live online-ordering entries. Most menu CONTENT is owned
// by MenuFetchJob (auto-scraped on online-ordering connect) — this controller
// never scrapes inline. POST /refresh re-dispatches the job (forced).
// POST /scan/apply applies AI-extracted items from a user-uploaded menu
// photo/PDF via MenuScanApplier, independent of any scrape. Owner-authored
// (manual) content — add/edit/delete a dish by hand — lives in
// MenuContentController. The menu itself IS also served publicly — see
// PublicMenuController — this controller's endpoints are the authenticated
// dashboard read/write surface. The full read shape is composed by
// MenuPayloadComposer (shared with MenuContentController).
class MenuController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly MenuSource $source,
        private readonly MenuScanApplier $scanApplier,
        private readonly MenuPayloadComposer $composer,
    ) {}

    // GET /api/platforms/menu/status — drives the integrations index card.
    public function status(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $menu = Menu::query()->where('user_id', $user->id)->first();

        // A menu is valid while it has a backing Uber Eats / DoorDash ordering
        // link OR its own owner-authored (scan/manual) content (which never
        // depended on one). Otherwise it's an orphaned scraped row whose links
        // were removed via a path that didn't clear the menu — guard against that
        // reading as connected when refresh() can't re-scrape it.
        if ($this->source->resolveAll($user) === null && ! $this->composer->hasOwnerContent($menu)) {
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

        return $this->success($this->composer->dashboardPayload($user));
    }

    // POST /api/platforms/menu/refresh — re-scrape (forced).
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): Menu is a food-business feature.
        // GET status()/show() stay open (UI-hidden, not endpoint-blocked) — only
        // this mutating path is gated. Runs FIRST — it's a 403 role/capability
        // restriction, distinct from the ownership gate below, and the
        // existing 403 for a non-food account must keep firing before it.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            return $this->error('Menu is not available for your account.', 403);
        }

        // Ownership gate (defence-in-depth — the menu is already scoped by
        // $user->id, so this can never actually deny the caller today; it
        // exists for parity with every other mutating controller and any
        // future by-id path). No row yet → authorize a skeleton carrying the
        // caller's own user_id (SEC-106).
        $menu = Menu::query()->where('user_id', $user->id)->first();
        $this->authorizeForUser($user, 'update', $menu ?? new Menu(['user_id' => $user->id]));

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

        // Ownership gate — see refresh()'s comment (SEC-106). Enforced HERE,
        // not inside MenuScanApplier, so the service stays callable from
        // non-HTTP callers (e.g. an automatic Google-photos scan job) without
        // an HTTP-shaped actor.
        $menu = Menu::query()->where('user_id', $user->id)->first();
        $this->authorizeForUser($user, 'update', $menu ?? new Menu(['user_id' => $user->id]));

        $result = $this->scanApplier->apply($user, $request->validated()['items']);

        return $this->success($result);
    }
}
