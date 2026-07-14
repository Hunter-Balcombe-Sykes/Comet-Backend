<?php

use App\Models\Core\User\User;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Str;

// BE3 self-review: the public sitepage menu endpoint (GET
// /api/public/profiles/{handle}/menu) must serve scan-sourced menus (built
// via POST /platforms/menu/scan/apply, no scrape involved) exactly as well as
// scraped ones. Two things had to hold for this to work, both proven here:
//   - Menu::last_fetched_at must be set for a scan-only menu — the public
//     controller (and SitepageDataResolverService's page-presence check) gate
//     entirely on whereNotNull('last_fetched_at'). MenuScanApplier stamps it.
//   - Item serialization must not assume a platform source — a scan item has
//     zero menu_item_platforms rows, and the public payload never reads that
//     relation in the first place (order links are dashboard-only), so this
//     was already safe; the test below pins it down.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function publicMenuUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('serves a scan-only menu (no scrape ever ran) on the public endpoint', function () {
    $user = publicMenuUser('pubscan1');

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Public Scan Dish', 'description' => 'Straight from a photo.', 'price' => 11.5, 'category' => 'Mains'],
    ]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    expect($res->json('data.storeName'))->toBeNull(); // scan never sets a store name
    $category = $res->json('data.categories.0');
    expect($category['name'])->toBe('Mains');
    $item = $category['items'][0];
    expect($item['name'])->toBe('Public Scan Dish');
    expect($item['description'])->toBe('Straight from a photo.');
    // base_price formatted to 2dp — proves serialization doesn't require a
    // platform-sourced item (no menu_item_platforms rows exist for it).
    expect($item['price'])->toBe('11.50');
});

it('returns 404 for a handle with no menu at all', function () {
    $user = publicMenuUser('pubscan2');

    $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertStatus(404);
});
