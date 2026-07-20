<?php

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\MenuPlatformLink;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\DB;
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
    // A scan item has no pickup/delivery price, no currency (Uber-Eats-only),
    // and zero menu_item_platforms rows — additive fields degrade safely.
    expect($item['pickupPrice'])->toBeNull();
    expect($item['deliveryPrice'])->toBeNull();
    expect($item['currency'])->toBeNull();
    expect($item['platforms'])->toBe([]);
    expect($item['images'])->toBeNull();
    expect($item['links'])->toBeNull();
});

it('returns 404 for a handle with no menu at all', function () {
    $user = publicMenuUser('pubscan2');

    $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertStatus(404);
});

// ── T2/T3: split prices + per-platform order links + per-item currency ──

it('serves pickup/delivery prices, per-item currency, and per-platform links on the public endpoint', function () {
    $user = publicMenuUser('pubmenu1');

    $menu = Menu::create([
        'user_id' => $user->id,
        'content_source' => 'uber-eats',
        'currency' => 'AUD',
        'fetch_status' => 'ok',
        'last_fetched_at' => now(),
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Pizzas', 'position' => 0, 'source_platform' => 'uber-eats',
    ]);
    $item = MenuItem::create([
        'menu_id' => $menu->id,
        'category_id' => $category->id,
        'position' => 0,
        'name' => 'Margherita',
        'base_price' => 11.0,
        'pickup_price' => 11.0,
        'pickup_source' => 'doordash',
        'delivery_price' => 12.5,
        'delivery_source' => 'uber-eats',
        'currency' => 'AUD',
        'image_url' => 'https://ue/marg.jpg',
        'images' => ['https://ue/marg.jpg', 'https://dd/marg.jpg'],
    ]);
    MenuItemPlatform::create([
        'menu_item_id' => $item->id,
        'platform' => 'uber-eats',
        'delivery_price' => 12.5,
        'delivery_url' => 'https://www.ubereats.com/store/d',
    ]);
    MenuItemPlatform::create([
        'menu_item_id' => $item->id,
        'platform' => 'doordash',
        'pickup_price' => 11.0,
        'pickup_url' => 'https://www.doordash.com/store/x',
    ]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    $publicItem = $res->json('data.categories.0.items.0');
    expect($publicItem['id'])->toBe((string) $item->id);
    // Cross-platform image set rides alongside the single hero imageUrl.
    expect($publicItem['imageUrl'])->toBe('https://ue/marg.jpg');
    expect($publicItem['images'])->toBe(['https://ue/marg.jpg', 'https://dd/marg.jpg']);
    expect($publicItem['price'])->toBe('11.00');
    expect($publicItem['pickupPrice'])->toBe('11.00');
    expect($publicItem['deliveryPrice'])->toBe('12.50');
    expect($publicItem['currency'])->toBe('AUD');
    expect($publicItem['platforms'])->toHaveCount(2);
    expect($publicItem['platforms'][0]['platform'])->toBe('uber-eats');
    expect($publicItem['platforms'][0]['pickupUrl'])->toBeNull();
    expect($publicItem['platforms'][0]['deliveryUrl'])->toBe('https://www.ubereats.com/store/d');
    expect($publicItem['platforms'][0]['pickupPrice'])->toBeNull();
    expect((float) $publicItem['platforms'][0]['deliveryPrice'])->toBe(12.5);
    expect($publicItem['platforms'][1]['platform'])->toBe('doordash');
    expect((float) $publicItem['platforms'][1]['pickupPrice'])->toBe(11.0);
    expect($publicItem['platforms'][1]['deliveryUrl'])->toBeNull();
});

// ── Popularity ranks (menu_item / menu_category, keyed by stable ids) ──

it('surfaces menu_item and menu_category popularity ranks keyed by the persisted stable ids', function () {
    // The full scoring loop: item-seen beacons accrue analytics.item_views rows
    // under item_type=menu_item/menu_category keyed by the persisted UUIDs
    // (already pinned by ItemSeenIngestTest); compute writes
    // content_popularity_scores under the same keys; this endpoint annotates
    // each category/item with its rank. Stable across refreshes because
    // MenuFetchJob reuses ids for name-matched rows (MenuTest pins that).
    setupContentPopularityScoresTable();
    $user = createTenant('pubmenurank');

    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Pizzas', 'position' => 0, 'source_platform' => 'uber-eats',
    ]);
    $popular = MenuItem::create([
        'menu_id' => $menu->id, 'category_id' => $category->id, 'position' => 0, 'name' => 'Margherita', 'base_price' => 11.0,
    ]);
    $sleeper = MenuItem::create([
        'menu_id' => $menu->id, 'category_id' => $category->id, 'position' => 1, 'name' => 'Anchovy Special', 'base_price' => 13.0,
    ]);

    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        ['id' => (string) Str::uuid(), 'site_id' => $user->site->id, 'content_type' => 'menu_item', 'content_key' => (string) $popular->id, 'score' => 9.5, 'rank' => 1, 'computed_at' => $now],
        ['id' => (string) Str::uuid(), 'site_id' => $user->site->id, 'content_type' => 'menu_category', 'content_key' => (string) $category->id, 'score' => 7.0, 'rank' => 1, 'computed_at' => $now],
    ]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    expect($res->json('data.categories.0.popularityRank'))->toBe(1);
    $items = collect($res->json('data.categories.0.items'));
    expect($items->firstWhere('id', (string) $popular->id)['popularityRank'])->toBe(1);
    // Un-scored items degrade to null, not 0 — the frontend sums per category.
    expect($items->firstWhere('id', (string) $sleeper->id)['popularityRank'])->toBeNull();
});

// ── CCG-102: popularity ranks are single-flight cached, not re-read per request ──

it('serves the second popularity-ranked menu request from cache — one DB read, identical payload', function () {
    setupContentPopularityScoresTable();
    $user = createTenant('pubmenucache');

    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Pizzas', 'position' => 0, 'source_platform' => 'uber-eats',
    ]);
    $item = MenuItem::create([
        'menu_id' => $menu->id, 'category_id' => $category->id, 'position' => 0, 'name' => 'Margherita', 'base_price' => 11.0,
    ]);
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $user->site->id, 'content_type' => 'menu_item',
        'content_key' => (string) $item->id, 'score' => 9.5, 'rank' => 1, 'computed_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->enableQueryLog();
    $first = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();
    $second = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    // Both requests see the same rank — the cache didn't change the answer.
    expect($first->json('data.categories.0.items.0.popularityRank'))->toBe(1);
    expect($second->json())->toEqual($first->json());

    // The actual "not re-read per request" proof: only ONE query touches
    // content_popularity_scores across both HTTP calls.
    $rankQueries = array_filter($log, fn ($q) => str_contains($q['query'], 'content_popularity_scores'));
    expect(count($rankQueries))->toBe(1);
});

// ── Per-item platform deep links (links: {uber_eats?, doordash?}) ──

it('serves a DoorDash per-item deep link built from the store link + dd item id, and omits uber_eats', function () {
    $user = publicMenuUser('publinks1');

    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'doordash', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    MenuPlatformLink::create([
        'menu_id' => $menu->id, 'platform' => 'doordash',
        'store_url' => 'https://www.doordash.com/store/nandos-melbourne-876860', 'status' => 'ok',
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Feasts', 'position' => 0, 'source_platform' => 'doordash',
    ]);
    $withId = MenuItem::create([
        'menu_id' => $menu->id, 'category_id' => $category->id, 'position' => 0,
        'name' => 'Classic Feast', 'base_price' => 27.95, 'dd_external_id' => '157588920',
    ]);
    $withoutId = MenuItem::create([
        'menu_id' => $menu->id, 'category_id' => $category->id, 'position' => 1,
        'name' => 'Mystery Dish', 'base_price' => 9.0,
    ]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    $items = collect($res->json('data.categories.0.items'));
    $linked = $items->firstWhere('id', (string) $withId->id);
    // The verified DoorDash share-link form: store page + item_click query —
    // opens that exact dish's modal, not just the store.
    expect($linked['links'])->toBe([
        'doordash' => 'https://www.doordash.com/store/nandos-melbourne-876860/?event_type=item_click&item_id=157588920',
    ]);
    // uber_eats is honestly absent — memo23 exposes no per-item id, and a
    // store-level URL must never masquerade as an item link.
    expect($linked['links'])->not->toHaveKey('uber_eats');

    // No dd id → nothing item-level derivable → null (graceful absence).
    $unlinked = $items->firstWhere('id', (string) $withoutId->id);
    expect($unlinked['links'])->toBeNull();

    // Categories now expose their stable id alongside popularityRank.
    expect($res->json('data.categories.0.id'))->toBe((string) $category->id);
});

it('B6: item id is stable across a category reorder, so a URL keyed off it never points at the wrong dish (G6-1)', function () {
    // The old positional {categoryIndex}:{itemIndex} URL scheme broke exactly
    // like this: a refresh reshuffles category order and the SAME index now
    // resolves to a different dish. A stable persisted id can't drift.
    $user = publicMenuUser('pubmenu2');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $catA = MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Featured', 'position' => 0, 'source_platform' => 'uber-eats']);
    $catB = MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Mains', 'position' => 1, 'source_platform' => 'uber-eats']);
    $target = MenuItem::create([
        'menu_id' => $menu->id, 'category_id' => $catB->id, 'position' => 0, 'name' => 'Steak', 'base_price' => 30.0,
    ]);
    MenuItem::create([
        'menu_id' => $menu->id, 'category_id' => $catA->id, 'position' => 0, 'name' => 'Hero Dish', 'base_price' => 10.0,
    ]);

    $before = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();
    // Before a "refresh": Steak sits at category 1, item 0 — positional "1:0".
    expect($before->json('data.categories.1.items.0.name'))->toBe('Steak');
    $steakId = $before->json('data.categories.1.items.0.id');
    expect($steakId)->toBe((string) $target->id);

    // Simulate a refresh reshuffling category order (G6-1): "Mains" now sorts
    // first. Positional "1:0" would now resolve to "Hero Dish" — the wrong
    // dish — but looking the item up by its stable id still finds "Steak".
    $catB->update(['position' => 0]);
    $catA->update(['position' => 1]);

    $after = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();
    expect($after->json('data.categories.0.items.0.name'))->toBe('Steak');
    expect($after->json('data.categories.0.items.0.id'))->toBe($steakId);
    // The positional index that USED to mean Steak now means a different dish.
    expect($after->json('data.categories.1.items.0.name'))->toBe('Hero Dish');
});
