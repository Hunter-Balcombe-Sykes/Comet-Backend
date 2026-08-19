<?php

use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Replaces PublicMenuControllerTest, which exercised the legacy public menu
// wire on GET /api/public/profiles/{handle}/menu — a whole second read path
// serving categories + items straight off site.menus.
//
// Retired in slice 7, Phase 3 Task 10 (spec D2, 2026-08-16). `pools.menus` on
// GET /api/public/profiles/{handle} is a complete replacement: the same items
// and the same per-item DoorDash deep links, plus `collections` (categories
// AND ordering-platform store cards), `diningModes`, and permalinks with 301
// aliases this lane never had. The 2026-08-14 owner ruling says the sitepage
// frontend is REBUILT, not repaired, so there was no compatibility to
// preserve and repointing would have built a second read path nobody consumes.
//
// This pins the RETIREMENT, because a deletion with no guard is one merge away
// from being undone. Two properties matter:
//
//  1. the route is GONE from the router — not gated, not emptied. A handle
//     with a live fetched menu must 404 exactly like an unknown one, or the
//     wire has two menu sources again;
//  2. site.menus itself is untouched. The dashboard lane (/api/platforms/menu)
//     and MenuPayloadComposer both still read it, so a retirement that also
//     took the data would be a much larger change than D2 authorised.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Slice 7 Task 8: MenuScanApplier seeds this file's fixture menu into
    // content.* through ManualMenuWriter, not into site.menu_items.
    setupContentTables();
});

function retiredMenuRouteUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('404s the retired public menu endpoint even when the handle has a live fetched menu', function () {
    $user = retiredMenuRouteUser('menuretired1');

    // A scan-applied menu is the cheapest fixture that stamps last_fetched_at,
    // which is the exact gate the deleted controller keyed 200-vs-404 on. If
    // the route came back, THIS is the request that would serve 200 again.
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Retired Lane Dish', 'description' => 'Still in site.menus.', 'price' => 9.5, 'category' => 'Mains'],
    ]);

    $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertStatus(404);
});

it('404s the retired endpoint for an unknown handle too', function () {
    $this->getJson('/api/public/profiles/nobody-here/menu')->assertStatus(404);
});

// The router-level assertion. The request tests above would also pass if the
// route survived but every code path inside it happened to 404 — this one
// cannot be satisfied by anything except the route being gone.
it('has no registered route for the public menu path', function () {
    $menuRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn (string $uri) => str_ends_with($uri, '/menu') && str_starts_with($uri, 'api/public/'))
        ->values()
        ->all();

    expect($menuRoutes)->toBe([]);
});

// The data survives the endpoint. `pools.menus` composes from these same rows,
// so a retirement that dropped them would break the replacement it points at.
it('leaves site.menus intact for the dashboard and pool lanes', function () {
    $user = retiredMenuRouteUser('menuretired2');

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Pool Lane Dish', 'price' => 12.0, 'category' => 'Sides'],
    ]);

    $menu = Menu::query()->where('user_id', $user->id)->first();

    expect($menu)->not->toBeNull();
    expect($menu->last_fetched_at)->not->toBeNull();
});
