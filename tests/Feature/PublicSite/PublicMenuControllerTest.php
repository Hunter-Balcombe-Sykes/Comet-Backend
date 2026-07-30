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
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
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
    $item->categories()->attach($category->id, ['position' => 0]);
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
        'menu_id' => $menu->id, 'name' => 'Margherita', 'base_price' => 11.0,
    ]);
    $popular->categories()->attach($category->id, ['position' => 0]);
    $sleeper = MenuItem::create([
        'menu_id' => $menu->id, 'name' => 'Anchovy Special', 'base_price' => 13.0,
    ]);
    $sleeper->categories()->attach($category->id, ['position' => 1]);

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
        'menu_id' => $menu->id, 'name' => 'Margherita', 'base_price' => 11.0,
    ]);
    $item->categories()->attach($category->id, ['position' => 0]);
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
        'menu_id' => $menu->id,
        'name' => 'Classic Feast', 'base_price' => 27.95, 'dd_external_id' => '157588920',
    ]);
    $withId->categories()->attach($category->id, ['position' => 0]);
    $withoutId = MenuItem::create([
        'menu_id' => $menu->id,
        'name' => 'Mystery Dish', 'base_price' => 9.0,
    ]);
    $withoutId->categories()->attach($category->id, ['position' => 1]);

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
        'menu_id' => $menu->id, 'name' => 'Steak', 'base_price' => 30.0,
    ]);
    $target->categories()->attach($catB->id, ['position' => 0]);
    $hero = MenuItem::create([
        'menu_id' => $menu->id, 'name' => 'Hero Dish', 'base_price' => 10.0,
    ]);
    $hero->categories()->attach($catA->id, ['position' => 0]);

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

// ── Item URL slugs (site.item_slugs) — slug + aliases on the wire ──

it('serves the current slug for a freshly created item (minted by the MenuItem observer)', function () {
    setupItemSlugsTable();
    $user = publicMenuUser('pubslug1');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Pizzas', 'position' => 0, 'source_platform' => 'uber-eats',
    ]);
    $item = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Margherita Pizza', 'base_price' => 11.0]);
    $item->categories()->attach($category->id, ['position' => 0]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    $publicItem = $res->json('data.categories.0.items.0');
    expect($publicItem['slug'])->toBe('margherita-pizza');
    expect($publicItem['aliases'])->toBe([(string) $item->id]);
});

it('includes retired slugs + the raw uuid in aliases after a rename', function () {
    setupItemSlugsTable();
    $user = publicMenuUser('pubslug2');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Pizzas', 'position' => 0, 'source_platform' => 'uber-eats',
    ]);
    $item = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Old Name', 'base_price' => 11.0]);
    $item->categories()->attach($category->id, ['position' => 0]);
    $item->update(['name' => 'New Name']);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    $publicItem = $res->json('data.categories.0.items.0');
    expect($publicItem['slug'])->toBe('new-name');
    expect($publicItem['aliases'])->toEqualCanonicalizing(['old-name', (string) $item->id]);
});

// ── #50: the public menu wire contract ────────────────────────────────────
//
// This payload is an enumerated allowlist BY CONSTRUCTION — PublicMenuController
// writes every key as a literal, so a new column cannot reach the wire without a
// code edit (that is why #50 was closed WONTFIX rather than given an
// array_intersect_key, which on a literal map is a tautology). What was missing
// was any pin on the resulting shape: this endpoint is unauthenticated, CDN-
// cached for 15 minutes by AddPublicCacheHeaders, and has NO golden master and NO
// frontend contract doc. The three tests below are that pin.

it('pins the exact public menu key set at every nesting level (#50)', function () {
    // ONE fully-populated menu so every key is non-null and therefore actually
    // asserted. Read the two failure modes before "fixing" a diff here:
    //   - a NEW key means you are adding a field to the unauthenticated,
    //     CDN-cached public wire. Confirm it is not internal (see the
    //     INTENTIONAL EXCLUSIONS block on PublicMenuController).
    //   - a MISSING key means you removed a field the Astro sitepage may
    //     render, and there is no frontend contract doc for this endpoint to
    //     check against. Verify against partna-frontend before proceeding.
    setupContentPopularityScoresTable();
    setupItemSlugsTable();
    $user = createTenant('pubwire1');

    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'doordash', 'store_name' => 'Nandos',
        'currency' => 'AUD', 'fetch_status' => 'ok', 'last_fetched_at' => now(),
        'logo_url' => 'https://dd/logo.png', 'rating' => 4.5, 'review_count' => 120,
    ]);
    MenuPlatformLink::create([
        'menu_id' => $menu->id, 'platform' => 'doordash',
        'store_url' => 'https://www.doordash.com/store/nandos-melbourne-876860', 'status' => 'ok',
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Feasts', 'position' => 0, 'source_platform' => 'doordash',
    ]);
    $item = MenuItem::create([
        'menu_id' => $menu->id, 'name' => 'Classic Feast', 'description' => 'Whole chicken.',
        'image_url' => 'https://dd/feast.jpg',
        'images' => ['https://dd/feast.jpg', 'https://ue/feast.jpg'],
        'base_price' => 27.95, 'pickup_price' => 26.0, 'delivery_price' => 29.5,
        'currency' => 'AUD', 'rating' => 92.0, 'rating_count' => 431,
        'badges' => [['text' => '#1 Most liked', 'type' => 'popular']],
        'dd_external_id' => '157588920',
    ]);
    $item->categories()->attach($category->id, ['position' => 0]);
    MenuItemPlatform::create([
        'menu_item_id' => $item->id, 'platform' => 'doordash',
        'pickup_price' => 26.0, 'pickup_url' => 'https://www.doordash.com/store/x',
    ]);
    MenuItemPlatform::create([
        'menu_item_id' => $item->id, 'platform' => 'uber-eats',
        'delivery_price' => 29.5, 'delivery_url' => 'https://www.ubereats.com/store/d',
    ]);
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        ['id' => (string) Str::uuid(), 'site_id' => $user->site->id, 'content_type' => 'menu_item', 'content_key' => (string) $item->id, 'score' => 9.5, 'rank' => 1, 'computed_at' => now()->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'site_id' => $user->site->id, 'content_type' => 'menu_category', 'content_key' => (string) $category->id, 'score' => 7.0, 'rank' => 1, 'computed_at' => now()->toDateTimeString()],
    ]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();
    $body = $res->json();

    // toEqualCanonicalizing on the KEYS (not assertExactJson) — uuids, ranks and
    // slugs vary per run, the key set is what the contract is.
    expect(array_keys($body))->toEqualCanonicalizing(['data']);
    expect(array_keys($body['data']))->toEqualCanonicalizing(['storeName', 'currency', 'categories']);

    $cat = $body['data']['categories'][0];
    expect(array_keys($cat))->toEqualCanonicalizing(['name', 'id', 'popularityRank', 'items']);

    $publicItem = $cat['items'][0];
    expect(array_keys($publicItem))->toEqualCanonicalizing([
        'id', 'slug', 'aliases', 'name', 'description', 'imageUrl', 'images',
        'price', 'pickupPrice', 'deliveryPrice', 'currency', 'rating',
        'ratingCount', 'badges', 'platforms', 'links', 'popularityRank',
    ]);

    expect(array_keys($publicItem['platforms'][0]))->toEqualCanonicalizing([
        'platform', 'pickupUrl', 'deliveryUrl', 'pickupPrice', 'deliveryPrice',
    ]);

    // badges + images are the only structures emitted without their inner keys
    // enumerated, so their shape is closed at the WRITERS (DoorDashMenuDriver::
    // badges(), MenuScanApplier::mergeDietaryBadges() → {text, type?}).
    expect(array_keys($publicItem['badges'][0]))->toBeIn([['text', 'type'], ['text'], ['type']]);
    expect($publicItem['images'])->toBe(['https://dd/feast.jpg', 'https://ue/feast.jpg']);

    // Every asserted key was genuinely populated — this can't pass on nulls.
    expect($publicItem['links'])->not->toBeNull();
    expect($publicItem['popularityRank'])->toBe(1);
    expect($cat['popularityRank'])->toBe(1);
    expect($publicItem['slug'])->toBe('classic-feast');
});

it('never leaks an internal menu column onto the public wire (#50)', function () {
    // You cannot "inject an unlisted key" into this payload — there is no
    // key-carrying structure to inject into, which IS the evidence for the
    // enumerated-allowlist verdict. So: write a unique sentinel into every
    // internal free-text column and scan the RAW body, which catches a leak at
    // any nesting depth that a key-walk would miss.
    //
    // Deliberately NOT sentinelled: menus.fetch_status and
    // menu_platform_links.status are CHECK-constrained in Postgres
    // (menus_fetch_status_check / menu_platform_links_status_check) but NOT in
    // the SQLite test mirror — a sentinel would pass here and fail prod with
    // 23514. Same for the numeric/timestamp columns (menus.rating,
    // menus.review_count, menu_platform_links.synced_at). Those are covered by
    // the key-set pin in the test above instead.
    $user = publicMenuUser('pubwire2');

    $menu = Menu::create([
        'user_id' => $user->id,
        'store_name' => 'Nandos',
        'currency' => 'AUD',
        'fetch_status' => 'ok',
        'last_fetched_at' => now(),
        'content_source' => 'INTERNAL-SENTINEL-content-source',
        'logo_url' => 'INTERNAL-SENTINEL-logo-url',
        'pickup_platform' => 'INTERNAL-SENTINEL-pickup-platform',
        'delivery_platform' => 'INTERNAL-SENTINEL-delivery-platform',
        // What the owner deliberately HID from their sitepage, and the raw scan
        // transcript — the two most damaging things on this list.
        'scan_items' => [['name' => 'INTERNAL-SENTINEL-scan-item']],
        'suppressed_items' => ['INTERNAL-SENTINEL-suppressed-item'],
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Feasts', 'position' => 0,
        'source_platform' => 'INTERNAL-SENTINEL-source-platform',
    ]);
    $item = MenuItem::create([
        'menu_id' => $menu->id, 'name' => 'Classic Feast', 'base_price' => 27.95,
        'pickup_source' => 'INTERNAL-SENTINEL-pickup-source',
        'delivery_source' => 'INTERNAL-SENTINEL-delivery-source',
        // Boolean — no string to scan for, so assert key-absence below.
        'is_manual' => true,
    ]);
    $item->categories()->attach($category->id, ['position' => 0]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    // Raw JSON, not the decoded array — a leak nested inside any structure fails.
    expect($res->getContent())->not->toContain('INTERNAL-SENTINEL-');
    // Sanity: the response really did render this menu, so the scan above is
    // not passing on an empty/404 body.
    expect($res->json('data.categories.0.items.0.name'))->toBe('Classic Feast');
});

it('keeps the public/dashboard split an invariant: authoring bookkeeping stays dashboard-only (#50)', function () {
    // MenuPayloadComposer::categories() emits isManual / pickupSource /
    // deliverySource for the OWNER's dashboard. If someone ever "shares one
    // Resource between both surfaces", those three get promoted to the public,
    // CDN-cached wire silently. Both spellings are asserted — the snake_case
    // column name and the camelCase key the composer actually emits.
    $user = publicMenuUser('pubwire3');

    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'doordash', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Feasts', 'position' => 0, 'source_platform' => 'doordash',
    ]);
    $item = MenuItem::create([
        'menu_id' => $menu->id, 'name' => 'Classic Feast', 'base_price' => 27.95,
        'pickup_source' => 'doordash', 'delivery_source' => 'uber-eats', 'is_manual' => true,
    ]);
    $item->categories()->attach($category->id, ['position' => 0]);

    $publicItem = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")
        ->assertOk()
        ->json('data.categories.0.items.0');

    expect($publicItem)->not->toHaveKey('is_manual');
    expect($publicItem)->not->toHaveKey('isManual');
    expect($publicItem)->not->toHaveKey('pickup_source');
    expect($publicItem)->not->toHaveKey('pickupSource');
    expect($publicItem)->not->toHaveKey('delivery_source');
    expect($publicItem)->not->toHaveKey('deliverySource');
    // The category's provenance is dashboard-only too.
    expect($this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->json('data.categories.0'))
        ->not->toHaveKey('source_platform');
});

it('degrades to a null slug and id-only aliases when the table exists but this item has no row yet', function () {
    // The table is present (as it always is in production — backend ships
    // the migration first) but this specific item predates the backfill:
    // insert the row directly, bypassing MenuItem::create/the observer, so
    // no item_slugs row exists for it.
    setupItemSlugsTable();
    $user = publicMenuUser('pubslug3');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD',
        'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $category = MenuCategory::create([
        'menu_id' => $menu->id, 'name' => 'Pizzas', 'position' => 0, 'source_platform' => 'uber-eats',
    ]);
    $itemId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => $itemId, 'menu_id' => $menu->id, 'name' => 'Margherita', 'base_price' => 11.0,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.menu_item_categories')->insert([
        'menu_item_id' => $itemId, 'menu_category_id' => $category->id, 'position' => 0,
    ]);

    $res = $this->getJson("/api/public/profiles/{$user->handle_lc}/menu")->assertOk();

    $publicItem = $res->json('data.categories.0.items.0');
    expect($publicItem['slug'])->toBeNull();
    expect($publicItem['aliases'])->toBe([$itemId]);
});
