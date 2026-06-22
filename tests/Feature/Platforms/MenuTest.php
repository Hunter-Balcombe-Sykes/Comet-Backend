<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function menuUser(string $h): User
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

/**
 * Insert an online-ordering entry row at a controlled created_at. $type sets
 * data.type (pickup/delivery) the way the Google Business harvest does; a null
 * type is a manual link (no data).
 */
function ordering(User $user, string $url, ?string $type, string $at): IntegrationConnection
{
    Carbon::setTestNow($at);
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => array_filter([
            'id' => $rid,
            'provider' => 'custom',
            'url' => $url,
            'name' => 'Order',
            'source' => $type !== null ? 'google-business' : 'manual',
            'data' => $type !== null ? ['type' => $type] : null,
        ], fn ($v) => $v !== null),
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    Carbon::setTestNow();

    return $row;
}

/**
 * Mock LinkCardScraper to echo whatever URL it's handed (no live HTTP) — so a
 * test can add several different ordering links and exercise the per-store
 * merge-on-add. snapshotOrMinimal returns a minimal card carrying the input url.
 */
function fakeEchoOrderingScraper(): void
{
    test()->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('snapshotOrMinimal')->andReturnUsing(fn ($u) => [
            'url' => $u,
            'name' => 'Uber Eats',
            'description' => null,
            'favicon' => null,
            'logo' => null,
        ]);
    });
}

/** Seed a relational menu (one row + categories + items) for the read endpoints. */
function seedMenu(User $user, array $menuAttrs, array $categories): Menu
{
    $menu = Menu::create(array_merge([
        'user_id' => $user->id,
        'content_source' => 'uber-eats',
        'currency' => 'AUD',
        'fetch_status' => 'ok',
    ], $menuAttrs));

    foreach ($categories as $ci => $category) {
        $cat = MenuCategory::create([
            'menu_id' => $menu->id,
            'name' => $category['name'],
            'position' => $ci,
            'source_platform' => 'uber-eats',
        ]);
        foreach (($category['items'] ?? []) as $ii => $item) {
            MenuItem::create(array_merge([
                'menu_id' => $menu->id,
                'category_id' => $cat->id,
                'position' => $ii,
                'name' => 'Item',
            ], $item));
        }
    }

    return $menu;
}

// ── Source resolution (Uber Eats > DoorDash > none) ───────────────────

it('resolves uber eats over doordash for menu content', function () {
    $user = menuUser('m1');
    // DoorDash is newer, but Uber Eats wins on content priority regardless.
    ordering($user, 'https://www.doordash.com/store/ollies-12345/', null, '2026-06-17 10:00:00');
    ordering($user, 'https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY', null, '2026-06-17 09:00:00');

    $resolved = app(MenuSource::class)->resolve($user);
    expect($resolved['platform'])->toBe('uber-eats');
    expect($resolved['storeUrl'])->toBe('https://www.ubereats.com/au/store/ollies/abc');
});

it('falls back to doordash when there is no uber eats link', function () {
    $user = menuUser('m2');
    ordering($user, 'https://www.doordash.com/store/ollies-12345/?utm=x', null, '2026-06-17 10:00:00');

    $resolved = app(MenuSource::class)->resolve($user);
    expect($resolved['platform'])->toBe('doordash');
    expect($resolved['storeUrl'])->toBe('https://www.doordash.com/store/ollies-12345');
});

it('resolves no menu source for non uber/doordash ordering links', function () {
    $user = menuUser('m3');
    ordering($user, 'https://www.menulog.com.au/restaurants-ollies', null, '2026-06-17 10:00:00');

    expect(app(MenuSource::class)->resolve($user))->toBeNull();
});

it('maps pickup and delivery platforms from the typed ordering links', function () {
    $user = menuUser('m3b');
    // DoorDash carries pickup, Uber Eats carries delivery — the user's scenario.
    ordering($user, 'https://www.doordash.com/store/ollies-1/', 'pickup', '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/au/store/ollies/abc', 'delivery', '2026-06-17 10:00:00');

    $plan = app(MenuSource::class)->resolveAll($user);
    expect($plan['contentSource'])->toBe('uber-eats');     // UE preferred for content
    expect($plan['pickupPlatform'])->toBe('doordash');
    expect($plan['deliveryPlatform'])->toBe('uber-eats');
    expect($plan['ddUrl'])->toBe('https://www.doordash.com/store/ollies-1');
    expect($plan['ueUrl'])->toBe('https://www.ubereats.com/au/store/ollies/abc');
});

// ── Read-time order-link computation ──────────────────────────────────

it('routes pickup and delivery to the most-recent typed entry', function () {
    $user = menuUser('m4');
    ordering($user, 'https://www.ubereats.com/store/old-pickup', 'pickup', '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/store/new-pickup', 'pickup', '2026-06-17 10:00:00');
    ordering($user, 'https://www.ubereats.com/store/delivery', 'delivery', '2026-06-17 09:30:00');

    $links = app(MenuSource::class)->links($user);
    expect($links['pickupUrl'])->toBe('https://www.ubereats.com/store/new-pickup');
    expect($links['deliveryUrl'])->toBe('https://www.ubereats.com/store/delivery');
    expect($links['orderUrl'])->toBeNull();
});

it('falls back to a single order button when no typed links exist', function () {
    $user = menuUser('m5');
    ordering($user, 'https://www.ubereats.com/store/old', null, '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/store/new', null, '2026-06-17 10:00:00');

    $links = app(MenuSource::class)->links($user);
    expect($links['pickupUrl'])->toBeNull();
    expect($links['deliveryUrl'])->toBeNull();
    expect($links['orderUrl'])->toBe('https://www.ubereats.com/store/new');
});

// ── MenuFetchJob (scrape → merge → persist relational) ────────────────

it('scrapes and stores the relational menu on source change', function () {
    $user = menuUser('m6');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetch')->once()->andReturn([
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'price' => 12.5]]]],
        ]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->content_source)->toBe('uber-eats');
    expect($menu->fetch_status)->toBe('ok');
    expect($menu->uber_eats_store_url)->toBe('https://www.ubereats.com/store/x');
    expect($menu->store_name)->toBe('Ollies');
    expect(MenuCategory::query()->where('menu_id', $menu->id)->value('name'))->toBe('Pizzas');
    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($item->name)->toBe('Margherita');
    expect($item->base_price)->toBe(12.5);
});

it('skips the paid scrape when the store url is unchanged', function () {
    $user = menuUser('m7');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'uber_eats_store_url' => 'https://www.ubereats.com/store/x',
        'fetch_status' => 'ok',
    ]);
    MenuCategory::create(['menu_id' => $menu->id, 'name' => 'A', 'position' => 0, 'source_platform' => 'uber-eats']);

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetch')->never());

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect(MenuCategory::query()->where('menu_id', $menu->id)->value('name'))->toBe('A');
});

it('forces a re-scrape and replaces the menu even when the url is unchanged', function () {
    $user = menuUser('m8');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'uber_eats_store_url' => 'https://www.ubereats.com/store/x', 'fetch_status' => 'ok',
    ]);
    MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Old', 'position' => 0, 'source_platform' => 'uber-eats']);

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetch')->once()->andReturn([
            'store' => ['name' => 'Ollies', 'rating' => 4.5, 'reviewCount' => 100, 'currency' => 'AUD'],
            'categories' => [['name' => 'Fresh', 'items' => [['name' => 'New', 'price' => 9.0]]]],
        ]);
    });

    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    $menu->refresh();
    expect($menu->rating)->toBe(4.5);
    expect(MenuCategory::query()->where('menu_id', $menu->id)->pluck('name')->all())->toBe(['Fresh']);
});

it('clears the menu when no ordering source remains', function () {
    $user = menuUser('m9');
    Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'uber_eats_store_url' => 'https://www.ubereats.com/store/x', 'fetch_status' => 'ok',
    ]);

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetch')->never());

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

// ── MenuController ────────────────────────────────────────────────────

it('reports menu status with item count and source', function () {
    $user = menuUser('m10');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Pizzas', 'items' => [['name' => 'A'], ['name' => 'B']]],
    ]);

    actingAsUser($user)->getJson('/api/platforms/menu/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('itemCount', 2)
        ->assertJsonPath('source', 'uber-eats')
        ->assertJsonPath('fetchStatus', 'ok');
});

it('reports menu disconnected when the backing ordering link is gone (orphan guard)', function () {
    $user = menuUser('m10b');
    // A scraped menu row with NO online-ordering connection backing it — the
    // orphan state. status() must report disconnected, not serve the stale menu
    // (which refresh() can't re-scrape anyway, since resolveAll() is null).
    seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Pizzas', 'items' => [['name' => 'A']]],
    ]);

    actingAsUser($user)->getJson('/api/platforms/menu/status')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('itemCount', 0)
        ->assertJsonPath('source', null);
});

it('does not serve an orphaned menu from the full menu endpoint', function () {
    $user = menuUser('m10c');
    seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Pizzas', 'items' => [['name' => 'A']]],
    ]);

    actingAsUser($user)->getJson('/api/platforms/menu')
        ->assertOk()
        ->assertJsonPath('source', null)
        ->assertJsonPath('categories', []);
});

it('returns the full menu with per-mode prices and computed order links', function () {
    $user = menuUser('m11');
    ordering($user, 'https://www.ubereats.com/store/p', 'pickup', '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/store/d', 'delivery', '2026-06-17 10:00:00');
    seedMenu($user, ['content_source' => 'uber-eats', 'rating' => 4.7], [
        ['name' => 'Pizzas', 'items' => [[
            'name' => 'Margherita', 'base_price' => 11.0,
            'delivery_price' => 12.5, 'delivery_source' => 'uber-eats',
            'pickup_price' => 11.0, 'pickup_source' => 'doordash',
            'rating' => 95, 'badges' => [['text' => '#1 Most liked']],
            'platforms' => [
                ['platform' => 'uber-eats', 'price' => 12.5, 'modes' => ['delivery'], 'url' => 'https://www.ubereats.com/store/d'],
                ['platform' => 'doordash', 'price' => 11.0, 'modes' => ['pickup'], 'url' => 'https://www.doordash.com/store/x'],
            ],
        ]]],
    ]);

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();

    expect($res->json('source'))->toBe('uber-eats');
    expect((float) $res->json('rating'))->toBe(4.7);
    $item = $res->json('categories.0.items.0');
    expect($item['name'])->toBe('Margherita');
    expect((float) $item['basePrice'])->toBe(11.0);
    expect((float) $item['pickupPrice'])->toBe(11.0);
    expect($item['pickupSource'])->toBe('doordash');
    expect((float) $item['deliveryPrice'])->toBe(12.5);
    expect((float) $item['rating'])->toBe(95.0);
    expect($item['badges'][0]['text'])->toBe('#1 Most liked');
    // Per-platform availability surfaces with prices, modes, and order urls.
    expect($item['platforms'])->toHaveCount(2);
    expect($item['platforms'][0]['platform'])->toBe('uber-eats');
    expect((float) $item['platforms'][0]['price'])->toBe(12.5);
    expect($item['platforms'][0]['modes'])->toBe(['delivery']);
    expect($item['platforms'][1]['platform'])->toBe('doordash');
    expect($item['platforms'][1]['modes'])->toBe(['pickup']);
    expect($res->json('links.pickupUrl'))->toBe('https://www.ubereats.com/store/p');
    expect($res->json('links.deliveryUrl'))->toBe('https://www.ubereats.com/store/d');
});

it('refresh dispatches a forced menu fetch job', function () {
    Queue::fake();
    $user = menuUser('m12');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');

    actingAsUser($user)->postJson('/api/platforms/menu/refresh')
        ->assertOk()
        ->assertJsonPath('fetchStatus', 'pending');

    Queue::assertPushed(MenuFetchJob::class, fn ($job) => $job->userId === (string) $user->id && $job->force === true);
});

it('refresh 422s when there is no ordering source', function () {
    $user = menuUser('m13');
    actingAsUser($user)->postJson('/api/platforms/menu/refresh')->assertStatus(422);
});

// ── Union across both platforms (persisted platforms[]) ───────────────

it('unions both platforms and persists a per-platform availability list', function () {
    $user = menuUser('m14');
    // Uber Eats store offers delivery, DoorDash store offers pickup (typed links).
    ordering($user, 'https://www.ubereats.com/store/x?diningMode=DELIVERY', 'delivery', '2026-06-17 09:00:00');
    ordering($user, 'https://www.doordash.com/store/x', 'pickup', '2026-06-17 10:00:00');

    // Both platforms scraped: a shared dish (Burrito) + a DoorDash-only dish (Churros).
    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetch')->with('https://www.ubereats.com/store/x', 'uber-eats', Mockery::any())->once()->andReturn([
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Mains', 'items' => [['name' => 'Burrito', 'price' => 17.0, 'image' => 'https://ue/b.jpg']]]],
        ]);
        $m->shouldReceive('fetch')->with('https://www.doordash.com/store/x', 'doordash', Mockery::any(), Mockery::any())->once()->andReturn([
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [
                ['name' => 'Mains', 'items' => [['name' => 'Burrito', 'price' => 15.5, 'description' => 'Loaded burrito.']]],
                ['name' => 'Sweets', 'items' => [['name' => 'Churros', 'price' => 8.0]]],
            ],
        ]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();

    // The shared dish: one row, both platforms, gap-filled description from DD.
    $burrito = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Burrito')->firstOrFail();
    expect($burrito->base_price)->toBe(15.5);                 // min across platforms
    expect($burrito->pickup_price)->toBe(15.5);              // DoorDash offers pickup
    expect($burrito->pickup_source)->toBe('doordash');
    expect($burrito->delivery_price)->toBe(17.0);            // Uber Eats offers delivery
    expect($burrito->delivery_source)->toBe('uber-eats');
    expect($burrito->description)->toBe('Loaded burrito.');  // gap-filled from DD
    expect($burrito->image_url)->toBe('https://ue/b.jpg');   // UE image preferred
    expect($burrito->platforms)->toHaveCount(2);
    expect(collect($burrito->platforms)->pluck('platform')->all())->toBe(['uber-eats', 'doordash']);

    // The DoorDash-only dish appears (not dropped) with a single-platform entry.
    $churros = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Churros')->firstOrFail();
    expect($churros->platforms)->toHaveCount(1);
    expect($churros->platforms[0]['platform'])->toBe('doordash');
    expect($churros->platforms[0]['modes'])->toBe(['pickup']);
});

// ── Online-ordering store consolidation (one store = one entry) ────────

it('collapses a pickup and delivery link for one store into a single entry', function () {
    $user = menuUser('m15');
    // The Google-harvest scenario: same Uber Eats store, two typed rows (the
    // diningMode query param differs, so they were two rows / a visible dupe).
    ordering($user, 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP', 'pickup', '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY', 'delivery', '2026-06-17 10:00:00');

    $res = actingAsUser($user)->getJson('/api/platforms/online-ordering/entries')->assertOk();

    // ONE consolidated entry carrying both mode URLs.
    expect($res->json('entries'))->toHaveCount(1);
    $entry = $res->json('entries.0');
    expect($entry['data']['pickupUrl'])->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP');
    expect($entry['data']['deliveryUrl'])->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY');
});

it('merges a second mode link for the same store into the existing entry on add', function () {
    Queue::fake();
    $user = menuUser('m16');
    fakeEchoOrderingScraper();

    // First add — a pickup-typed Uber Eats link.
    actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', [
        'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP',
    ])->assertOk()->assertJsonCount(1, 'entries');

    // Second add — the SAME store, delivery variant — folds into the same row.
    $res = actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', [
        'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY',
    ])->assertOk();

    // Still ONE row in the DB (no duplicate) and one consolidated entry.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->count())->toBe(1);
    expect($res->json('entries'))->toHaveCount(1);
    expect($res->json('entries.0.data.pickupUrl'))->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP');
    expect($res->json('entries.0.data.deliveryUrl'))->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY');
});
