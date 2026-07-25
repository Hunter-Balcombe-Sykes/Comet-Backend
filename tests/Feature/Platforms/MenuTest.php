<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\MenuPlatformLink;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuSource;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // MenuFetchJob + MenuItemObserver both write site.item_slugs. Without the
    // table their best-effort try/catch swallows a "no such table" and any slug
    // assertion below would silently pass against nothing.
    setupItemSlugsTable();
});

// Business + food sector: Menu is a food-business-only capability (2026-07-15
// sector gating; partna/individual never qualify — can_use_menu requires
// isBusiness()). Every test in this file exercises Menu content/endpoints, so
// this is the one persona the whole suite needs; no test here asserts
// account-type-dependent behaviour, so there's no other default to preserve.
function menuUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'sector' => 'restaurant',
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
        // addEntry now calls minimalCard (async JOB-1) instead of snapshotOrMinimal.
        $m->shouldReceive('minimalCard')->andReturnUsing(fn ($u) => [
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
            $platforms = $item['platforms'] ?? [];
            unset($item['platforms']);
            $menuItem = MenuItem::create(array_merge([
                'menu_id' => $menu->id,
                'name' => 'Item',
            ], $item));
            $menuItem->categories()->attach($cat->id, ['position' => $ii]);
            foreach ($platforms as $p) {
                MenuItemPlatform::create([
                    'menu_item_id' => $menuItem->id,
                    'platform' => $p['platform'],
                    'pickup_price' => $p['pickupPrice'] ?? null,
                    'pickup_url' => $p['pickupUrl'] ?? null,
                    'delivery_price' => $p['deliveryPrice'] ?? null,
                    'delivery_url' => $p['deliveryUrl'] ?? null,
                ]);
            }
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
    expect($plan['storeUrls']['doordash'])->toBe('https://www.doordash.com/store/ollies-1');
    expect($plan['storeUrls']['uber-eats'])->toBe('https://www.ubereats.com/au/store/ollies/abc');
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

    // Untyped single store → fetchStores returns the platform's fused menu.
    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5, 'image' => 'https://ue/marg.jpg']]]],
        ]]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->content_source)->toBe('uber-eats');
    expect($menu->fetch_status)->toBe('ok');
    expect($menu->platformLinks->firstWhere('platform', 'uber-eats')?->store_url)->toBe('https://www.ubereats.com/store/x');
    expect($menu->store_name)->toBe('Ollies');
    expect(MenuCategory::query()->where('menu_id', $menu->id)->value('name'))->toBe('Pizzas');
    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($item->name)->toBe('Margherita');
    expect($item->base_price)->toBe(12.5);
    // Image set persists hero-first alongside the single image_url.
    expect($item->image_url)->toBe('https://ue/marg.jpg');
    expect($item->images)->toBe(['https://ue/marg.jpg']);
});

it('reuses category and item ids across rebuilds when names match (stable identity)', function () {
    // Popularity scores (analytics item_id) and sitepage item-detail URLs both
    // key on menu_items.id across refreshes — a wholesale rebuild must therefore
    // re-assign the SAME id to a dish that still exists (matched by normalized
    // name, the same identity MenuMerger uses cross-platform). Removed dishes
    // free their id; new dishes mint fresh ones.
    $user = menuUser('m6ids');
    ordering($user, 'https://www.ubereats.com/store/ids', null, '2026-06-17 10:00:00');

    $run1 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Pizzas', 'items' => [
                ['name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5],
                ['name' => 'Pepperoni', 'pickupPrice' => 14.0, 'deliveryPrice' => 14.0],
            ]],
            ['name' => 'Sides', 'items' => [['name' => 'Fries', 'pickupPrice' => 6.0, 'deliveryPrice' => 6.0]]],
        ],
    ]];
    // Run 2: categories reordered upstream, Pepperoni dropped, Calzone added.
    $run2 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Sides', 'items' => [['name' => 'Fries', 'pickupPrice' => 6.5, 'deliveryPrice' => 6.5]]],
            ['name' => 'Pizzas', 'items' => [
                ['name' => 'Margherita', 'pickupPrice' => 13.0, 'deliveryPrice' => 13.0],
                ['name' => 'Calzone', 'pickupPrice' => 15.0, 'deliveryPrice' => 15.0],
            ]],
        ],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($run1, $run2) {
        $m->shouldReceive('fetchStores')->twice()->andReturn($run1, $run2);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $idsBefore = MenuItem::query()->where('menu_id', $menu->id)->pluck('id', 'name');
    $pizzasIdBefore = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Pizzas')->value('id');

    // Forced refresh (the unchanged-skip gate would otherwise no-op the rebuild).
    (new MenuFetchJob((string) $user->id, force: true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $idsAfter = MenuItem::query()->where('menu_id', $menu->id)->pluck('id', 'name');
    expect($idsAfter['Margherita'])->toBe($idsBefore['Margherita']);
    expect($idsAfter['Fries'])->toBe($idsBefore['Fries']);
    // The refreshed price landed on the SAME row (a real update, not a lookalike).
    expect((float) MenuItem::query()->whereKey($idsBefore['Margherita'])->value('base_price'))->toBe(13.0);
    // Removed dish is gone; its id was not silently re-assigned to the new dish.
    expect($idsAfter)->not->toHaveKey('Pepperoni');
    expect($idsAfter['Calzone'])->not->toBe($idsBefore['Pepperoni']);
    // Categories keep their identity through the upstream reorder too.
    expect(MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Pizzas')->value('id'))
        ->toBe($pizzasIdBefore);
});

it('collapses a same-named dish in two categories into ONE item with BOTH memberships, id-stable across rebuilds', function () {
    // Multi-category model (2026-07-21): "Coke" under Drinks AND Combos is a
    // single menu_items row with two pivot memberships — the duplicate-rows
    // problem this redesign removes. Its id must survive forced rebuilds
    // (popularity scores + item URLs key on it).
    $user = menuUser('m6xcat');
    ordering($user, 'https://www.ubereats.com/store/xcat', null, '2026-06-17 10:00:00');

    $runs = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Drinks', 'items' => [['name' => 'Coke', 'pickupPrice' => 4.0, 'deliveryPrice' => 4.0]]],
            ['name' => 'Combos', 'items' => [['name' => 'Coke', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0]]],
        ],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($runs) {
        $m->shouldReceive('fetchStores')->times(3)->andReturn($runs, $runs, $runs);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();

    $coke = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Coke')->get();
    expect($coke)->toHaveCount(1);
    $cokeCategories = fn () => MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Coke')
        ->firstOrFail()->categories->pluck('name')->sort()->values()->all();
    expect($cokeCategories())->toBe(['Combos', 'Drinks']);
    // First occurrence (Drinks) wins the display fields.
    expect((float) $coke->first()->pickup_price)->toBe(4.0);
    $idBefore = (string) $coke->first()->id;

    (new MenuFetchJob((string) $user->id, force: true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect((string) MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Coke')->firstOrFail()->id)->toBe($idBefore);
    expect($cokeCategories())->toBe(['Combos', 'Drinks']);

    (new MenuFetchJob((string) $user->id, force: true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect((string) MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Coke')->firstOrFail()->id)->toBe($idBefore);
    expect($cokeCategories())->toBe(['Combos', 'Drinks']);
});

it('skips the paid scrape when the store url is unchanged', function () {
    $user = menuUser('m7');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok',
    ]);
    MenuPlatformLink::create([
        'menu_id' => $menu->id, 'platform' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x', 'status' => 'ok',
    ]);
    MenuCategory::create(['menu_id' => $menu->id, 'name' => 'A', 'position' => 0, 'source_platform' => 'uber-eats']);

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->never());

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect(MenuCategory::query()->where('menu_id', $menu->id)->value('name'))->toBe('A');
});

it('re-scrapes when a connected platform last came back unavailable', function () {
    $user = menuUser('m7b');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    // URL unchanged + fetch_status ok, but the UE scrape last failed — must NOT skip.
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok',
    ]);
    MenuPlatformLink::create([
        'menu_id' => $menu->id, 'platform' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x', 'status' => 'unavailable',
    ]);

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5]]]],
        ]]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    $menu->load('platformLinks');
    expect($menu->platformLinks->firstWhere('platform', 'uber-eats')?->status)->toBe('ok');
    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Margherita')->exists())->toBeTrue();
});

it('forces a re-scrape and replaces the menu even when the url is unchanged', function () {
    $user = menuUser('m8');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok',
    ]);
    MenuPlatformLink::create(['menu_id' => $menu->id, 'platform' => 'uber-eats', 'store_url' => 'https://www.ubereats.com/store/x']);
    MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Old', 'position' => 0, 'source_platform' => 'uber-eats']);

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'rating' => 4.5, 'reviewCount' => 100, 'currency' => 'AUD'],
            'categories' => [['name' => 'Fresh', 'items' => [['name' => 'New', 'pickupPrice' => 9.0, 'deliveryPrice' => 9.0]]]],
        ]]);
    });

    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    $menu->refresh();
    expect($menu->rating)->toBe(4.5);
    expect(MenuCategory::query()->where('menu_id', $menu->id)->pluck('name')->all())->toBe(['Fresh']);
});

it('clears the menu when no ordering source remains', function () {
    $user = menuUser('m9');
    Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok',
    ]);

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->never());

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

// ── TXN-101: sync status must never claim success before persist() lands ──
// Pre-fix, the per-platform menu_platform_links status/synced_at write ran
// BEFORE persist() (the actual content write) — so a persist() failure left
// the platform link claiming "ok, synced just now" for content that was
// never actually written. That both misleads the dashboard and hides the
// platform from menu:retry-unavailable's self-heal query (status = 'unavailable'),
// so a genuine failure could get stuck forever without a manual refresh.

it('does not mark the platform sync status ok when persist() fails after a successful scrape', function () {
    $user = menuUser('txn101');
    ordering($user, 'https://www.ubereats.com/store/txn101', null, '2026-06-17 10:00:00');

    // A prior link already sitting at 'unavailable' — pre-fix code would
    // clobber this with a false 'ok' + a fresh synced_at even though this
    // run's content never lands. Re-read the row after create() so the
    // baseline is the DB-round-tripped value (the datetime cast truncates to
    // second precision) — comparing against the in-memory Carbon instead
    // would spuriously fail on microseconds nothing in this test touches.
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok']);
    MenuPlatformLink::create([
        'menu_id' => $menu->id, 'platform' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/txn101',
        'status' => 'unavailable', 'synced_at' => now()->subDay(),
    ]);
    $priorSyncedAt = MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->firstOrFail()->synced_at;

    // A live dish with a minted slug — the item_slugs reconcile (271-DINT-1)
    // hangs off persist() too, so the same ordering guarantee has to hold for
    // it: a failed rebuild must not free or re-mint a single slug.
    $priorCategory = MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Mains', 'position' => 0, 'source_platform' => 'uber-eats']);
    $priorDish = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Existing Dish']);
    $priorDish->categories()->attach($priorCategory->id, ['position' => 0]);
    $priorSlugs = DB::connection('pgsql')->table('site.item_slugs')->orderBy('item_key')->get()->toArray();
    expect($priorSlugs)->toHaveCount(1);

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5]]]],
        ]]);
    });

    // Subclass forces persist() (the content write) to fail deterministically
    // — everything else in handle() runs for real, including whatever writes
    // the fix orders around persist().
    $job = new class((string) $user->id) extends MenuFetchJob
    {
        protected function persist(Menu $menu, string $contentSource, array $merged, Carbon $now): void
        {
            throw new RuntimeException('simulated persist failure');
        }
    };

    expect(fn () => $job->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class)))
        ->toThrow(RuntimeException::class, 'simulated persist failure');

    $link = MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->firstOrFail();
    // Neither field may have advanced — both would falsely claim a sync that
    // never actually completed.
    expect($link->status)->toBe('unavailable');
    expect($link->synced_at->equalTo($priorSyncedAt))->toBeTrue();

    // The slug registry is byte-identical — the reconcile lives after the
    // transaction closure, so a failed persist() never reaches it.
    expect(DB::connection('pgsql')->table('site.item_slugs')->orderBy('item_key')->get()->toArray())->toEqual($priorSlugs);
});

it('still marks the platform sync status ok when persist() succeeds (control)', function () {
    $user = menuUser('txn101ok');
    ordering($user, 'https://www.ubereats.com/store/txn101ok', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5]]]],
        ]]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $link = MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->firstOrFail();
    expect($link->status)->toBe('ok');
    expect($link->synced_at)->not->toBeNull();
});

// ── DINT-102: a menu with live categories can never be deleted ────────────
// MenuFetchJob::clearScrapedContent() only ever calls $menu->delete() after
// checking `! $menu->categories()->exists()` — this MenuObserver makes that
// invariant a model-level guarantee (not just a convention at one call site)
// so a future delete path can't silently orphan menu_categories/menu_items
// rows under a vanished menu.

it('blocks deleting a menu that still has live categories, and the categories survive', function () {
    $user = menuUser('dint102');
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok']);
    MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Mains', 'position' => 0, 'source_platform' => 'uber-eats']);

    expect(fn () => $menu->delete())->toThrow(RuntimeException::class);

    // Blocked — both the menu row (no soft-delete) and its category survive.
    expect(Menu::query()->whereKey($menu->id)->whereNull('deleted_at')->exists())->toBeTrue();
    expect(MenuCategory::query()->where('menu_id', $menu->id)->exists())->toBeTrue();
});

it('blocks force-deleting a menu that still has live categories (PurgeSoftDeleted path)', function () {
    $user = menuUser('dint102fd');
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok']);
    MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Mains', 'position' => 0, 'source_platform' => 'uber-eats']);

    expect(fn () => $menu->forceDelete())->toThrow(RuntimeException::class);

    expect(Menu::query()->whereKey($menu->id)->exists())->toBeTrue();
    expect(MenuCategory::query()->where('menu_id', $menu->id)->exists())->toBeTrue();
});

it('allows deleting a menu with no live categories (the legitimate clearScrapedContent path stays unblocked)', function () {
    $user = menuUser('dint102ok');
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok']);

    $menu->delete();

    expect(Menu::onlyTrashed()->whereKey($menu->id)->exists())->toBeTrue();
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
    $menu = seedMenu($user, ['content_source' => 'uber-eats', 'rating' => 4.7], [
        ['name' => 'Pizzas', 'items' => [[
            'name' => 'Margherita', 'base_price' => 11.0,
            'delivery_price' => 12.5, 'delivery_source' => 'uber-eats',
            'pickup_price' => 11.0, 'pickup_source' => 'doordash',
            'rating' => 95, 'badges' => [['text' => '#1 Most liked']],
            'platforms' => [
                ['platform' => 'uber-eats', 'pickupPrice' => null, 'pickupUrl' => null, 'deliveryPrice' => 12.5, 'deliveryUrl' => 'https://www.ubereats.com/store/d'],
                ['platform' => 'doordash', 'pickupPrice' => 11.0, 'pickupUrl' => 'https://www.doordash.com/store/x', 'deliveryPrice' => null, 'deliveryUrl' => null],
            ],
        ]]],
    ]);

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();

    expect($res->json('source'))->toBe('uber-eats');
    expect((float) $res->json('rating'))->toBe(4.7);
    // Category carries its persisted id (addresses PATCH/DELETE .../categories/{id})
    // and sourcePlatform (drives the dashboard's sync-detach warning).
    $dbCategory = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Pizzas')->firstOrFail();
    expect($res->json('categories.0.id'))->toBe((string) $dbCategory->id);
    expect($res->json('categories.0.sourcePlatform'))->toBe('uber-eats');
    $item = $res->json('categories.0.items.0');
    expect($item['name'])->toBe('Margherita');
    // Stable persisted id (fix-round P1) — mirrors PublicMenuController's
    // `id` field; Partna-Frontend's menu-item-detail URLs key off THIS
    // endpoint's id, not the public sitepage payload's.
    $dbItem = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Margherita')->firstOrFail();
    expect($item['id'])->toBe((string) $dbItem->id);
    // Scraped item — never manual.
    expect($item['isManual'])->toBeFalse();
    expect((float) $item['basePrice'])->toBe(11.0);
    expect((float) $item['pickupPrice'])->toBe(11.0);
    expect($item['pickupSource'])->toBe('doordash');
    expect((float) $item['deliveryPrice'])->toBe(12.5);
    expect((float) $item['rating'])->toBe(95.0);
    expect($item['badges'][0]['text'])->toBe('#1 Most liked');
    // Per-platform availability surfaces with per-mode prices + order urls.
    expect($item['platforms'])->toHaveCount(2);
    expect($item['platforms'][0]['platform'])->toBe('uber-eats');
    expect((float) $item['platforms'][0]['deliveryPrice'])->toBe(12.5);
    expect($item['platforms'][0]['deliveryUrl'])->toBe('https://www.ubereats.com/store/d');
    expect($item['platforms'][0]['pickupPrice'])->toBeNull();
    expect($item['platforms'][1]['platform'])->toBe('doordash');
    expect((float) $item['platforms'][1]['pickupPrice'])->toBe(11.0);
    expect($item['platforms'][1]['deliveryPrice'])->toBeNull();
    expect($res->json('links.pickupUrl'))->toBe('https://www.ubereats.com/store/p');
    expect($res->json('links.deliveryUrl'))->toBe('https://www.ubereats.com/store/d');
});

it('captures and serves the uber eats item currency and store dining modes end to end', function () {
    $user = menuUser('m20');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD', 'diningModes' => ['DELIVERY', 'PICKUP']],
            'categories' => [['name' => 'Pizzas', 'items' => [[
                'name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5, 'currency' => 'AUD',
            ]]]],
        ]]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->dining_modes)->toBe(['DELIVERY', 'PICKUP']);
    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($item->currency)->toBe('AUD');

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();
    expect($res->json('diningModes'))->toBe(['DELIVERY', 'PICKUP']);
    expect($res->json('categories.0.items.0.currency'))->toBe('AUD');
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

// ── SEC-106: ownership gate ordering ────────────────────────────────────
// refresh()/applyScan() now authorize 'update' against the caller's OWN menu
// (or a skeleton) as defence-in-depth, gated AFTER the existing can_use_menu
// capability check. A non-food account must still see the 403 role
// restriction, unaffected by the new ownership gate sitting behind it.

it('still 403s refresh for a non-food business account (capability check runs before the new ownership gate)', function () {
    $user = menuUser('m21nonfood');
    $user->forceFill(['sector' => 'barber'])->save();
    AccountCapabilities::flushCache();

    actingAsUser($user)->postJson('/api/platforms/menu/refresh')->assertStatus(403);
});

it('still 403s scan apply for a non-food business account (capability check runs before the new ownership gate)', function () {
    $user = menuUser('m21nonfoodscan');
    $user->forceFill(['sector' => 'barber'])->save();
    AccountCapabilities::flushCache();

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Should not apply', 'description' => null, 'price' => null, 'category' => null]],
    ])->assertStatus(403);
});

it('menu:retry-unavailable re-dispatches forced fetches only for recently-unavailable menus', function () {
    Queue::fake();

    // In-window: a connected platform last came back unavailable, fetched just now.
    $fresh = menuUser('m17');
    $freshMenu = Menu::create(['user_id' => $fresh->id, 'content_source' => 'doordash', 'fetch_status' => 'ok', 'last_fetched_at' => now()]);
    MenuPlatformLink::create(['menu_id' => $freshMenu->id, 'platform' => 'uber-eats', 'status' => 'unavailable']);
    MenuPlatformLink::create(['menu_id' => $freshMenu->id, 'platform' => 'doordash', 'status' => 'ok']);
    // Out-of-window: failed long ago — aged out, must NOT be retried forever.
    $stale = menuUser('m18');
    $staleMenu = Menu::create(['user_id' => $stale->id, 'content_source' => 'doordash', 'last_fetched_at' => now()->subHours(12)]);
    MenuPlatformLink::create(['menu_id' => $staleMenu->id, 'platform' => 'uber-eats', 'status' => 'unavailable']);
    // Healthy: both platforms ok — never selected.
    $ok = menuUser('m19');
    $okMenu = Menu::create(['user_id' => $ok->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok', 'last_fetched_at' => now()]);
    MenuPlatformLink::create(['menu_id' => $okMenu->id, 'platform' => 'uber-eats', 'status' => 'ok']);

    $this->artisan('menu:retry-unavailable')->assertSuccessful();

    Queue::assertPushed(MenuFetchJob::class, 1);
    Queue::assertPushed(MenuFetchJob::class, fn ($job) => $job->userId === (string) $fresh->id && $job->force === true);
});

// ── Union across both platforms (persisted platforms[]) ───────────────

it('unions both platforms and persists a per-platform availability list', function () {
    $user = menuUser('m14');
    // Uber Eats store offers delivery, DoorDash store offers pickup (typed links).
    ordering($user, 'https://www.ubereats.com/store/x?diningMode=DELIVERY', 'delivery', '2026-06-17 09:00:00');
    ordering($user, 'https://www.doordash.com/store/x', 'pickup', '2026-06-17 10:00:00');

    // Both platforms scraped (per-mode prices, the fetchStores shape): a shared dish
    // (Burrito) + a DoorDash-only dish (Churros). UE store offers delivery only,
    // DoorDash offers pickup only — so each item carries the one mode it priced.
    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn([
            'uber-eats' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [['name' => 'Mains', 'items' => [['name' => 'Burrito', 'pickupPrice' => null, 'deliveryPrice' => 17.0, 'image' => 'https://ue/b.jpg']]]],
            ],
            'doordash' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [
                    ['name' => 'Mains', 'items' => [['name' => 'Burrito', 'pickupPrice' => 15.5, 'deliveryPrice' => null, 'description' => 'Loaded burrito.']]],
                    ['name' => 'Sweets', 'items' => [['name' => 'Churros', 'pickupPrice' => 8.0, 'deliveryPrice' => null]]],
                ],
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
    $burrito->load('platformLinks');
    expect($burrito->platformLinks)->toHaveCount(2);
    expect($burrito->platformLinks->pluck('platform')->all())->toBe(['uber-eats', 'doordash']);
    expect((float) $burrito->platformLinks->firstWhere('platform', 'uber-eats')->delivery_price)->toBe(17.0);
    expect($burrito->platformLinks->firstWhere('platform', 'uber-eats')->pickup_price)->toBeNull();
    expect((float) $burrito->platformLinks->firstWhere('platform', 'doordash')->pickup_price)->toBe(15.5);
    expect($burrito->platformLinks->firstWhere('platform', 'doordash')->delivery_price)->toBeNull();

    // The DoorDash-only dish appears (not dropped) with a single-platform entry.
    $churros = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Churros')->firstOrFail();
    $churros->load('platformLinks');
    expect($churros->platformLinks)->toHaveCount(1);
    expect($churros->platformLinks->first()->platform)->toBe('doordash');
    expect((float) $churros->platformLinks->first()->pickup_price)->toBe(8.0);
    expect($churros->platformLinks->first()->delivery_price)->toBeNull();
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

    // First add — a pickup-typed Uber Eats link. Returns 202 (async JOB-1).
    actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', [
        'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP',
    ])->assertStatus(202)->assertJsonCount(1, 'entries');

    // Second add — the SAME store, delivery variant — folds into the same row.
    $res = actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', [
        'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY',
    ])->assertStatus(202);

    // Still ONE row in the DB (no duplicate) and one consolidated entry.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->count())->toBe(1);
    expect($res->json('entries'))->toHaveCount(1);
    expect($res->json('entries.0.data.pickupUrl'))->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP');
    expect($res->json('entries.0.data.deliveryUrl'))->toBe('https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY');
});

// ── DoorDash locale address — data minimisation (PRIV-2) ─────────────
// The full street address must never leave the backend towards Apify.
// Only city + state are forwarded; when neither is stored the field is null
// so the scraper applies its own AU fallback (DOORDASH_FALLBACK_ADDRESS).

it('resolveAll returns only city+state as the doordash locale address when a full street is stored', function () {
    $user = menuUser('m17');
    // A DoorDash ordering link is required for resolveAll() to return non-null.
    ordering($user, 'https://www.doordash.com/store/priv2-test/', null, '2026-06-17 10:00:00');

    // Create a site row and a workplace with both a full street and a city + state.
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'menu-'.substr($siteId, 0, 8),
    ]);
    Workplace::create([
        'site_id' => $siteId,
        'address' => '42 Home Street',   // sole-trader home address — must NOT reach Apify
        'city' => 'Melbourne',
        'state' => 'VIC',
    ]);

    $plan = app(MenuSource::class)->resolveAll($user);
    expect($plan)->not->toBeNull();
    // City + state only — the full street must be absent.
    expect($plan['address'])->toBe('Melbourne, VIC, Australia');
    expect($plan['address'])->not->toContain('42 Home Street');
});

// ── Wholesale-rebuild guard (CACHE-1) ─────────────────────────────────
// Runs MenuFetchJob twice with IDENTICAL scraper output and asserts that
// category names, item names, category count, item count, and per-item
// platform-link count are identical after both runs. Proves the delete→
// reinsert cycle is behavior-preserving (UUIDs may differ; content must not).

it('wholesale rebuild produces identical menu structure across two forced runs with the same scraper output', function () {
    $user = menuUser('mguard1');
    ordering($user, 'https://www.ubereats.com/store/guard', null, '2026-06-17 10:00:00');

    $scraperOutput = [
        'uber-eats' => [
            'store' => ['name' => 'Guard Eats', 'currency' => 'AUD'],
            'categories' => [
                ['name' => 'Mains', 'items' => [
                    ['name' => 'Burger', 'pickupPrice' => 12.0, 'deliveryPrice' => 13.0],
                    ['name' => 'Fries', 'pickupPrice' => 5.0, 'deliveryPrice' => 5.0],
                ]],
                ['name' => 'Drinks', 'items' => [
                    ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
                ]],
            ],
        ],
    ];

    // Run #1 — first build.
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->once()->andReturn($scraperOutput));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    // Category-grouped item names in display order (category position, then
    // per-membership pivot position) — the structure the readers render.
    $structure = fn () => MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->with('items')->get()
        ->flatMap(fn ($c) => $c->items->pluck('name'))->all();
    $cats1 = MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->pluck('name')->all();
    $items1 = $structure();
    $linkCount1 = MenuItemPlatform::query()->whereIn('menu_item_id', MenuItem::query()->where('menu_id', $menu->id)->pluck('id'))->count();

    // Run #2 — identical scraper output, forced rebuild.
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->once()->andReturn($scraperOutput));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu->refresh();
    $cats2 = MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->pluck('name')->all();
    $items2 = $structure();
    $linkCount2 = MenuItemPlatform::query()->whereIn('menu_item_id', MenuItem::query()->where('menu_id', $menu->id)->pluck('id'))->count();

    expect($cats2)->toBe($cats1);
    expect($items2)->toBe($items1);
    expect($linkCount2)->toBe($linkCount1);
    expect($menu->fetch_status)->toBe('ok');
});

// ── fix-round: real cross-run category-position stability through the DB ──
// MenuMerger's persisted-order stability (critic P1) is only real if
// MenuFetchJob actually feeds it the PREVIOUS scrape's persisted order before
// persist() wholesale-deletes it. Runs handle() twice with the SAME 3
// categories but a reshuffled scrape order the second time, and asserts the
// PERSISTED `position` column (not just in-memory merge output) is identical
// both times — this is what the dashboard/public-site read-time `orderBy
// position` actually depends on.

it('persists identical category positions across two scrapes even when the upstream scrape reorders categories (fix-round)', function () {
    $user = menuUser('mstable1');
    ordering($user, 'https://www.ubereats.com/store/stable', null, '2026-06-17 10:00:00');

    $run1Output = [
        'uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [
                ['name' => 'Featured items', 'items' => [['name' => 'Hero Dish', 'pickupPrice' => 10.0, 'deliveryPrice' => 10.0]]],
                ['name' => 'Mains', 'items' => [['name' => 'Steak', 'pickupPrice' => 30.0, 'deliveryPrice' => 30.0]]],
                ['name' => 'Sides', 'items' => [['name' => 'Fries', 'pickupPrice' => 6.0, 'deliveryPrice' => 6.0]]],
            ],
        ],
    ];
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->once()->andReturn($run1Output));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $positions1 = MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->pluck('name', 'position')->all();
    expect($positions1)->toBe([0 => 'Featured items', 1 => 'Mains', 2 => 'Sides']);

    // Run 2 — SAME 3 categories, upstream scrape reshuffled the array order.
    // A forced refresh always re-scrapes regardless of url/settled state.
    $run2Output = $run1Output;
    $run2Output['uber-eats']['categories'] = [
        $run1Output['uber-eats']['categories'][2], // Sides
        $run1Output['uber-eats']['categories'][0], // Featured items
        $run1Output['uber-eats']['categories'][1], // Mains
    ];
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->once()->andReturn($run2Output));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $positions2 = MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->pluck('name', 'position')->all();
    expect($positions2)->toBe($positions1);
});

it('resolveAll returns null as the doordash locale address when only a street is stored (no city/state)', function () {
    $user = menuUser('m18');
    ordering($user, 'https://www.doordash.com/store/priv2-nolocale/', null, '2026-06-17 10:00:00');

    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'menu-'.substr($siteId, 0, 8),
    ]);
    // Street only — no city or state stored.
    Workplace::create([
        'site_id' => $siteId,
        'address' => '99 Private Road',
    ]);

    $plan = app(MenuSource::class)->resolveAll($user);
    expect($plan)->not->toBeNull();
    // Null signals the scraper to apply DOORDASH_FALLBACK_ADDRESS ('Melbourne VIC, Australia').
    expect($plan['address'])->toBeNull();
});

// ── BE3: POST /platforms/menu/scan/apply (FE10 contract) ──────────────
// A user-uploaded menu photo/PDF is AI-extracted by the frontend into
// {name, description, price, category} items and POSTed here. Same-name
// items (case-insensitive, trimmed) get updated; unmatched names create a
// new item under a source_platform='scan' category. Distinct code path from
// MenuFetchJob/MenuMerger — no scraper involved.

it('creates a menu row via scan apply when the user has none yet', function () {
    $user = menuUser('scan1');
    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Margherita Pizza', 'description' => 'Classic.', 'price' => 14.5, 'category' => 'Pizzas']],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 0, 'added' => 1]);
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->content_source)->toBe('scan');
    expect($menu->fetch_status)->toBe('ok');
    expect($menu->last_fetched_at)->not->toBeNull();

    $category = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($category->name)->toBe('Pizzas');
    expect($category->source_platform)->toBe('scan');

    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($item->name)->toBe('Margherita Pizza');
    expect($item->description)->toBe('Classic.');
    expect((float) $item->base_price)->toBe(14.5);
});

it('scan apply carries dietary markers through validation onto the created item badges', function () {
    // MenuScanApplier has accepted dietary all along (the automatic Google-
    // photos/PDF scans use it), but ApplyMenuScanRequest had no rule for it —
    // validated() strips unruled keys, so a manual scan's dietary markers were
    // silently dropped at the HTTP boundary.
    $user = menuUser('scan-diet');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [[
            'name' => 'Falafel Bowl', 'description' => null, 'price' => 18.0,
            'category' => 'Mains', 'dietary' => ['Vegan', 'Gluten free'],
        ]],
    ])->assertOk();

    $item = MenuItem::query()->where('name', 'Falafel Bowl')->firstOrFail();
    $labels = array_map(fn ($b) => $b['text'] ?? null, (array) $item->badges);
    expect($labels)->toContain('Vegan');
    expect($labels)->toContain('Gluten free');
});

it('defaults new scan items with no category to a "Menu" category', function () {
    $user = menuUser('scan2');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Mystery Dish', 'description' => null, 'price' => null, 'category' => null]],
    ])->assertOk()->assertExactJson(['updated' => 0, 'added' => 1]);

    $menuId = Menu::query()->where('user_id', $user->id)->value('id');
    $category = MenuCategory::query()->where('menu_id', $menuId)->firstOrFail();
    expect($category->name)->toBe('Menu');
    expect($category->source_platform)->toBe('scan');
    expect($category->items()->firstOrFail()->base_price)->toBeNull();
});

it('updates an existing item by case-insensitive trimmed name match without nulling missing fields', function () {
    $user = menuUser('scan3');
    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Chicken Parma', 'description' => 'Original.', 'base_price' => 18.0]]],
    ]);

    // Scan sends a case/whitespace-different name, a new price, no description.
    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => '  chicken parma  ', 'description' => null, 'price' => 19.5, 'category' => null]],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 0]);
    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($item->name)->toBe('Chicken Parma');        // display name untouched
    expect($item->description)->toBe('Original.');      // NOT null'd out — scan omitted it
    expect((float) $item->base_price)->toBe(19.5);       // price updated
    expect(MenuCategory::query()->where('menu_id', $menu->id)->count())->toBe(1); // no new category
});

it('updates description when the scan provides it and leaves price alone when the scan omits it', function () {
    $user = menuUser('scan4');
    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Butter Chicken', 'description' => 'Old desc.', 'base_price' => 20.0]]],
    ]);

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Butter Chicken', 'description' => 'Creamy tomato curry.', 'price' => null, 'category' => null]],
    ])->assertOk()->assertExactJson(['updated' => 1, 'added' => 0]);

    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($item->description)->toBe('Creamy tomato curry.');
    expect((float) $item->base_price)->toBe(20.0); // untouched — scan omitted price
});

it('reports mixed updated/added counts for a batch with both matches and new items', function () {
    $user = menuUser('scan5');
    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Burger', 'base_price' => 15.0]]],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [
            ['name' => 'Burger', 'description' => 'Beef patty.', 'price' => 16.0, 'category' => null],
            ['name' => 'Fries', 'description' => null, 'price' => 6.0, 'category' => 'Sides'],
            ['name' => 'Cola', 'description' => null, 'price' => 4.0, 'category' => 'Sides'],
        ],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 2]);
    expect(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(3);
    // Both new items land in the SAME new "Sides" scan category, not two.
    $sides = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Sides')->firstOrFail();
    expect($sides->source_platform)->toBe('scan');
    expect($sides->items()->count())->toBe(2);
});

// ── BE3: multi-category name matching (one name = one dish, menu-wide) ──
// A dish listed under several sections ("Garlic Bread" as both a Starter and
// a Side) is ONE menu_items row with several pivot memberships. A scan match
// updates that single row; a scan category the dish isn't listed under yet
// ATTACHES (find-or-create among scan-owned categories) — unless a same-named
// membership (any source) already covers it, so a scraped "Sides" is never
// shadowed by a scan-owned duplicate.

it('updates the single multi-category dish in place and never shadows a same-named scraped category', function () {
    $user = menuUser('scan15');
    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Starters', 'items' => [['name' => 'Garlic Bread', 'base_price' => 8.0]]],
        ['name' => 'Sides', 'items' => []],
    ]);
    // The one Garlic Bread row is listed under BOTH sections.
    $garlic = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    $sidesCat = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Sides')->firstOrFail();
    $garlic->categories()->attach($sidesCat->id, ['position' => 0]);

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Garlic Bread', 'description' => 'Toasted, buttery.', 'price' => 6.5, 'category' => 'sides']],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 0]);
    $garlic->refresh();
    expect((float) $garlic->base_price)->toBe(6.5);
    expect($garlic->description)->toBe('Toasted, buttery.');
    // 'sides' was already covered by the scraped "Sides" membership — no
    // scan-owned duplicate category may appear.
    expect(MenuCategory::query()->where('menu_id', $menu->id)->count())->toBe(2);
    expect($garlic->categories()->count())->toBe(2);
});

it('attaches the scan category to a matched dish that is not listed under it yet', function () {
    $user = menuUser('scan16');
    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Starters', 'items' => [['name' => 'Garlic Bread', 'base_price' => 8.0]]],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Garlic Bread', 'description' => null, 'price' => 7.0, 'category' => 'Dinner']],
    ])->assertOk();

    // One row still — the scan grew its memberships instead of duplicating it.
    expect($res->json())->toBe(['updated' => 1, 'added' => 0]);
    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Garlic Bread')->count())->toBe(1);

    $garlic = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect((float) $garlic->base_price)->toBe(7.0);
    expect($garlic->categories->pluck('name')->sort()->values()->all())->toBe(['Dinner', 'Starters']);
    $dinner = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Dinner')->firstOrFail();
    expect($dinner->source_platform)->toBe('scan');
});

it('still updates by name alone when exactly one item shares that name and the scan supplies no category (pinned existing behavior)', function () {
    $user = menuUser('scan17');
    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Caesar Salad', 'base_price' => 12.0]]],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'caesar salad', 'description' => 'With anchovies.', 'price' => 13.0, 'category' => null]],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 0]);
    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect((float) $item->base_price)->toBe(13.0);
    expect($item->description)->toBe('With anchovies.');
});

it('422s scan apply for an empty (whitespace-only) item name', function () {
    $user = menuUser('scan6');
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => '   ', 'description' => null, 'price' => null, 'category' => null]],
    ])->assertStatus(422);
    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('422s scan apply for more than 200 items', function () {
    $user = menuUser('scan7');
    $items = array_fill(0, 201, ['name' => 'Item', 'description' => null, 'price' => null, 'category' => null]);
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', ['items' => $items])->assertStatus(422);
});

it('422s scan apply for a non-numeric price', function () {
    $user = menuUser('scan8');
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Item', 'description' => null, 'price' => 'free', 'category' => null]],
    ])->assertStatus(422);
});

it('422s scan apply for a negative price', function () {
    $user = menuUser('scan8b');
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Item', 'description' => null, 'price' => -5, 'category' => null]],
    ])->assertStatus(422);
});

it('422s scan apply for a price above the sane upper bound', function () {
    $user = menuUser('scan8c');
    // 140000 = the rule comment's motivating case ($1400.00 scanned with the
    // decimal point dropped). max:100000 is inclusive — exactly 100000 passes.
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Item', 'description' => null, 'price' => 140000, 'category' => null]],
    ])->assertStatus(422);
});

it('scan apply accepts boundary-legal prices — zero and just under the cap', function () {
    $user = menuUser('scan8d');

    // price 0 also guards the applier: base_price is set via `?? null` / a
    // `!== null` check, so a free item stores 0, not null — a truthiness
    // regression (empty(0) is true) would silently drop it.
    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [
            ['name' => 'Free Tasting', 'description' => null, 'price' => 0, 'category' => null],
            ['name' => 'Banquet Buyout', 'description' => null, 'price' => 99999.99, 'category' => null],
        ],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 0, 'added' => 2]);
    $menuId = Menu::query()->where('user_id', $user->id)->value('id');
    $free = MenuItem::query()->where('menu_id', $menuId)->where('name', 'Free Tasting')->firstOrFail();
    expect((float) $free->base_price)->toBe(0.0);
    $banquet = MenuItem::query()->where('menu_id', $menuId)->where('name', 'Banquet Buyout')->firstOrFail();
    expect((float) $banquet->base_price)->toBe(99999.99);
});

// ── BE3: refresh-survival — scan content must outlive a scraper rebuild ──
// MenuFetchJob wholesale-deletes+reinserts menu_categories/menu_items on
// every real scrape (persist()) and on losing the last ordering link
// (handle()'s early-return). Both paths must skip source_platform='scan'
// rows or a user's scanned menu silently vanishes on the next refresh.

it('preserves scan-sourced categories and items across a forced scraper rebuild (refresh-survival)', function () {
    $user = menuUser('scan9');
    ordering($user, 'https://www.ubereats.com/store/scanguard', null, '2026-06-17 10:00:00');

    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Old Scraped Dish', 'base_price' => 10.0]]],
    ]);
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Special Lasagna', 'description' => 'Family recipe.', 'price' => 22.0, 'category' => 'Specials']],
    ])->assertOk();

    $scanCategory = MenuCategory::query()->where('menu_id', $menu->id)->where('source_platform', 'scan')->firstOrFail();
    $scanItem = $scanCategory->items()->firstOrFail();

    // A forced scraper refresh returns entirely different scraped content.
    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Fresh', 'items' => [['name' => 'New Scraped Dish', 'pickupPrice' => 9.0, 'deliveryPrice' => 9.0]]]],
        ]]);
    });
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // Scraped content rebuilt as expected...
    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Old Scraped Dish')->exists())->toBeFalse();
    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'New Scraped Dish')->exists())->toBeTrue();

    // ...but the scan-sourced category + item survived, untouched.
    expect(MenuCategory::query()->where('id', $scanCategory->id)->exists())->toBeTrue();
    $scanItem->refresh();
    expect($scanItem->name)->toBe('Special Lasagna');
    expect((float) $scanItem->base_price)->toBe(22.0);
});

it('preserves scan-sourced content when the user disconnects their only ordering platform', function () {
    $user = menuUser('scan10');
    ordering($user, 'https://www.ubereats.com/store/scanguard2', null, '2026-06-17 10:00:00');

    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Scraped Dish', 'base_price' => 10.0]]],
    ]);
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Scanned Special', 'description' => null, 'price' => 12.0, 'category' => 'Specials']],
    ])->assertOk();
    $scanItem = MenuItem::query()->where('name', 'Scanned Special')->firstOrFail();

    // Remove the only ordering link — MenuFetchJob now dispatches with no
    // resolvable source at all (resolveAll() returns null).
    IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->delete();

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // Scraped content is gone, but the scan item + the menu row itself survive.
    expect(MenuItem::query()->where('name', 'Scraped Dish')->exists())->toBeFalse();
    expect(MenuItem::query()->where('id', $scanItem->id)->exists())->toBeTrue();
    $menu->refresh();
    expect($menu->trashed())->toBeFalse();
    expect($menu->content_source)->toBe('scan');
});

// ── BE3: reconnect-after-disconnect must not be silently skipped ────────
// clearScrapedContent() kept the menu's stale menu_platform_links rows alive
// (status 'ok', the ORIGINAL store_url) even though, by definition, no
// platform is connected at that point. On reconnect to the SAME store,
// handle()'s urlUnchanged+settled skip-gate compared against that leftover
// row and wrongly saw "nothing changed" — silently no-op'ing a scrape that
// should have run, permanently losing the store's scraped content.

it('re-scrapes after a disconnect + reconnect to the same store — a stale platformLinks row must not fool the skip-gate', function () {
    $user = menuUser('scan18');
    $storeUrl = 'https://www.ubereats.com/store/reconnectguard';
    ordering($user, $storeUrl, null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->twice()->andReturn(
            ['uber-eats' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [['name' => 'Mains', 'items' => [['name' => 'Original Scraped Dish', 'pickupPrice' => 10.0, 'deliveryPrice' => 10.0]]]],
            ]],
            ['uber-eats' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [['name' => 'Mains', 'items' => [['name' => 'Post-Reconnect Dish', 'pickupPrice' => 11.0, 'deliveryPrice' => 11.0]]]],
            ]],
        );
    });

    // Connect → real scrape (handle(), not seedMenu()) so a genuine
    // menu_platform_links row (status 'ok') gets written — the exact row the
    // bug leaves behind after disconnect.
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Original Scraped Dish')->exists())->toBeTrue();
    expect(MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->exists())->toBeTrue();

    // Scan content that must survive the disconnect.
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Scanned Special', 'description' => null, 'price' => 12.0, 'category' => 'Specials']],
    ])->assertOk();

    // Disconnect the only ordering link.
    IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->delete();
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // Scraped content + the stale platformLinks row are gone; scan content survives.
    expect(MenuItem::query()->where('name', 'Original Scraped Dish')->exists())->toBeFalse();
    expect(MenuItem::query()->where('name', 'Scanned Special')->exists())->toBeTrue();
    expect(MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->exists())->toBeFalse();

    // Reconnect the SAME store — must force a real re-scrape, not a silent skip.
    ordering($user, $storeUrl, null, '2026-06-17 11:00:00');
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    expect(MenuItem::query()->where('name', 'Post-Reconnect Dish')->exists())->toBeTrue();
    $menu->refresh();
    expect($menu->content_source)->toBe('uber-eats');
});

// ── BE3: scan-only menus (no ordering platform ever connected) ─────────
// MenuController's status()/show() historically treated "no resolvable
// Uber Eats/DoorDash link" as an orphan-menu signal and hid the menu
// entirely. A menu built purely from scans never had (or needed) an
// ordering link, so it must never be treated as orphaned.

it('reports menu status as connected for a scan-only menu with no ordering platform', function () {
    $user = menuUser('scan11');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Solo Scan Item', 'description' => null, 'price' => 9.0, 'category' => null]],
    ])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/menu/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('itemCount', 1)
        ->assertJsonPath('source', 'scan');
});

it('serves the full menu for a scan-only menu with no ordering platform', function () {
    $user = menuUser('scan12');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Solo Scan Item', 'description' => 'Tasty.', 'price' => 9.0, 'category' => 'Mains']],
    ])->assertOk();

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();
    expect($res->json('source'))->toBe('scan');
    expect($res->json('categories.0.name'))->toBe('Mains');
    $item = $res->json('categories.0.items.0');
    expect($item['name'])->toBe('Solo Scan Item');
    expect($item['description'])->toBe('Tasty.');
    expect((float) $item['basePrice'])->toBe(9.0);
    // No menu_item_platforms rows on a scan item — must serialize without
    // order links, not assume/require a platform source.
    expect($item['platforms'])->toBe([]);
});

// ── BE3: platforms-key seam — scanned item on a CONNECTED menu ─────────
// Frontend MenuOrderActions treats `item.platforms` being ABSENT (undefined)
// as "pre-platforms-schema item — fall back to the restaurant-level pickup/
// delivery/order links" (menu-section.tsx). A scan-added item has zero
// menu_item_platforms rows; if the dashboard GET ever omitted `platforms`
// instead of emitting `[]` for such an item, a menu that ALSO has a
// connected Uber Eats/DoorDash link would wrongly attach that platform's
// order button to a scanned dish that isn't actually on it. Distinct from
// scan12 above: that menu has NO ordering connection at all, so its
// restaurant-level `links` are already null and the bug couldn't manifest
// even if `platforms` were omitted — this test needs a real connected link
// to actually exercise the misleading-button risk.

it('serves an explicit platforms:[] (not an omitted key) for a scanned item on a menu with a connected ordering platform', function () {
    $user = menuUser('scan13');
    // A real, connected Uber Eats link — links.orderUrl will be non-null,
    // which is the exact bait a legacy-fallback bug would wrongly serve.
    ordering($user, 'https://www.ubereats.com/store/scanseam', null, '2026-06-17 10:00:00');
    seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [[
            'name' => 'Scraped Dish', 'base_price' => 10.0,
            'platforms' => [
                ['platform' => 'uber-eats', 'pickupPrice' => null, 'pickupUrl' => null, 'deliveryPrice' => 10.0, 'deliveryUrl' => 'https://www.ubereats.com/store/scanseam'],
            ],
        ]]],
    ]);

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Scanned Dish', 'description' => null, 'price' => 7.0, 'category' => 'Specials']],
    ])->assertOk();

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();
    // Confirm the restaurant-level fallback link really is populated here...
    expect($res->json('links.orderUrl'))->toBe('https://www.ubereats.com/store/scanseam');

    $items = collect($res->json('categories'))->flatMap(fn ($c) => $c['items'])->keyBy('name');
    $scanned = $items['Scanned Dish'];
    // ...but the scanned item has zero menu_item_platforms rows, so its own
    // `platforms` key must be present-and-empty, never omitted, or the
    // frontend's legacy branch would inherit the restaurant's Uber Eats button.
    expect(array_key_exists('platforms', $scanned))->toBeTrue();
    expect($scanned['platforms'])->toBe([]);
    // The scraped sibling dish keeps its own real per-platform entry, unaffected.
    expect($items['Scraped Dish']['platforms'])->toHaveCount(1);
});

// ── 271-DINT-1: the wholesale rebuild must keep site.item_slugs in step ──
// persist() writes items through the query builder (bulk insert + mass
// delete), which bypasses MenuItemObserver entirely — so pre-fix a scraped
// dish never got a pretty URL at all, and a dropped dish squatted its slug
// forever (item_slugs_unique_slug is NOT partial, so even a retired row
// blocks reuse). The reconcile still runs AFTER the rebuild transaction
// commits — best-effort per-dish work that must not be able to roll the
// rebuild back — though the allocator is now safe inside a transaction too
// (insertOrIgnore behind a savepoint; see ItemSlugAllocator::insertUnique).

/** The live item_slugs row (id + slug) for a menu item, or null. */
function menuSlugRow(User $user, string $itemId): ?object
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'menu_item')
        ->where('item_key', $itemId)->where('is_current', 1)
        ->first(['id', 'slug']);
}

/** Every item_slugs row for a menu item, current and retired. */
function menuSlugRowCount(User $user, string $itemId): int
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'menu_item')
        ->where('item_key', $itemId)->count();
}

/** Mock one Uber Eats scrape returning $categories verbatim. */
function mockMenuScrape(array $categories): void
{
    test()->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => $categories,
    ]]));
}

function runMenuFetch(User $user): void
{
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
}

/** One scraped dish entry with the price keys the merger needs. */
function scrapedDish(string $name): array
{
    return ['name' => $name, 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5];
}

it('mints an item_slugs row for a brand-new scraped dish', function () {
    $user = menuUser('slugmint');
    ordering($user, 'https://www.ubereats.com/store/slugmint', null, '2026-06-17 10:00:00');

    mockMenuScrape([['name' => 'Tacos', 'items' => [scrapedDish('Fish Tacos'), scrapedDish('Beef Tacos')]]]);
    runMenuFetch($user);

    $fish = MenuItem::query()->where('name', 'Fish Tacos')->firstOrFail();
    $beef = MenuItem::query()->where('name', 'Beef Tacos')->firstOrFail();
    expect(menuSlugRow($user, $fish->id)?->slug)->toBe('fish-tacos');
    expect(menuSlugRow($user, $beef->id)?->slug)->toBe('beef-tacos');
});

it('frees a dropped dish slug on the next scrape and leaves the survivor row untouched', function () {
    $user = menuUser('slugdrop');
    ordering($user, 'https://www.ubereats.com/store/slugdrop', null, '2026-06-17 10:00:00');

    mockMenuScrape([['name' => 'Tacos', 'items' => [scrapedDish('Fish Tacos'), scrapedDish('Beef Tacos')]]]);
    runMenuFetch($user);

    $fish = MenuItem::query()->where('name', 'Fish Tacos')->firstOrFail();
    $beef = MenuItem::query()->where('name', 'Beef Tacos')->firstOrFail();
    $fishRow = menuSlugRow($user, $fish->id);

    // Scrape 2 drops the beef tacos entirely.
    mockMenuScrape([['name' => 'Tacos', 'items' => [scrapedDish('Fish Tacos')]]]);
    runMenuFetch($user);

    // The dropped dish's slug is HARD-deleted (a retired row would still squat
    // the slug — the unique index is not partial).
    expect(menuSlugRowCount($user, $beef->id))->toBe(0);
    // ...and the survivor's row is the SAME row, not a delete-and-remint.
    $fishAfter = menuSlugRow($user, $fish->id);
    expect($fishAfter->id)->toBe($fishRow->id);
    expect($fishAfter->slug)->toBe('fish-tacos');
});

it('does not churn item_slugs rows when an identical scrape reuses every dish identity', function () {
    $user = menuUser('slugchurn');
    ordering($user, 'https://www.ubereats.com/store/slugchurn', null, '2026-06-17 10:00:00');

    $categories = [['name' => 'Tacos', 'items' => [scrapedDish('Fish Tacos'), scrapedDish('Beef Tacos')]]];
    mockMenuScrape($categories);
    runMenuFetch($user);

    $before = DB::connection('pgsql')->table('site.item_slugs')->orderBy('item_key')->get(['id', 'item_key', 'slug'])->toArray();
    expect($before)->toHaveCount(2);

    mockMenuScrape($categories);
    runMenuFetch($user);

    // Byte-identical ROW IDS — asserting only the slug would still pass under a
    // delete-and-remint, which would reset redirect history and can permute the
    // -N suffixes between two same-based dishes.
    $after = DB::connection('pgsql')->table('site.item_slugs')->orderBy('item_key')->get(['id', 'item_key', 'slug'])->toArray();
    expect($after)->toEqual($before);
});

it('forgets a dropped dish before minting the replacement that shares its slug base', function () {
    $user = menuUser('slugorder');
    ordering($user, 'https://www.ubereats.com/store/slugorder', null, '2026-06-17 10:00:00');

    // "Café Latte" and "Cafe Latte" are DIFFERENT dishes to normalizeName()
    // (the accented byte is stripped, not transliterated → "caf latte" vs
    // "cafe latte"), so no identity is reused — but Str::slug transliterates
    // both to the same base `cafe-latte`.
    mockMenuScrape([['name' => 'Drinks', 'items' => [scrapedDish('Café Latte')]]]);
    runMenuFetch($user);

    $old = MenuItem::query()->where('name', 'Café Latte')->firstOrFail();
    expect(menuSlugRow($user, $old->id)?->slug)->toBe('cafe-latte');

    mockMenuScrape([['name' => 'Drinks', 'items' => [scrapedDish('Cafe Latte')]]]);
    runMenuFetch($user);

    $new = MenuItem::query()->where('name', 'Cafe Latte')->firstOrFail();
    expect($new->id)->not->toBe($old->id);
    // Ensure-before-forget would hand this `cafe-latte-2` — and ensureCurrent()
    // short-circuits on an unchanged base afterwards, so it would stay there
    // permanently.
    expect(menuSlugRow($user, $new->id)?->slug)->toBe('cafe-latte');
    expect(menuSlugRowCount($user, $old->id))->toBe(0);
});

it('frees scraped dish slugs when the last ordering link is removed but keeps manual dish slugs', function () {
    $user = menuUser('slugclear');
    ordering($user, 'https://www.ubereats.com/store/slugclear', null, '2026-06-17 10:00:00');

    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Scraped Dish', 'base_price' => 10.0]]],
    ]);
    $scraped = MenuItem::query()->where('name', 'Scraped Dish')->firstOrFail();
    $category = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();
    $manual = MenuItem::create(['menu_id' => $menu->id, 'name' => 'House Special', 'is_manual' => true]);
    $manual->categories()->attach($category->id, ['position' => 1]);

    expect(menuSlugRow($user, $scraped->id)?->slug)->toBe('scraped-dish');
    $manualRow = menuSlugRow($user, $manual->id);
    expect($manualRow?->slug)->toBe('house-special');

    // No ordering platform left at all → clearScrapedContent().
    IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->delete();
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    expect(menuSlugRowCount($user, $scraped->id))->toBe(0);
    expect(menuSlugRow($user, $manual->id)?->id)->toBe($manualRow->id);
});

it('leaves a manual dish item_slugs row untouched across a scraper rebuild', function () {
    $user = menuUser('slugmanual');
    ordering($user, 'https://www.ubereats.com/store/slugmanual', null, '2026-06-17 10:00:00');

    $menu = seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Old Scraped Dish', 'base_price' => 10.0]]],
    ]);
    $category = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();
    $manual = MenuItem::create(['menu_id' => $menu->id, 'name' => 'House Special', 'is_manual' => true]);
    $manual->categories()->attach($category->id, ['position' => 1]);
    $manualRow = menuSlugRow($user, $manual->id);
    expect($manualRow?->slug)->toBe('house-special');

    mockMenuScrape([['name' => 'Fresh', 'items' => [scrapedDish('New Scraped Dish')]]]);
    runMenuFetch($user);

    $manualAfter = menuSlugRow($user, $manual->id);
    expect($manualAfter->id)->toBe($manualRow->id);
    expect($manualAfter->slug)->toBe('house-special');
    // ...and the new scraped dish got its own.
    $fresh = MenuItem::query()->where('name', 'New Scraped Dish')->firstOrFail();
    expect(menuSlugRow($user, $fresh->id)?->slug)->toBe('new-scraped-dish');
});

// ── the collision surface Unit 5 opened: MenuScanApplier runs INSIDE a
// transaction. Every scraped dish now owns an item_slugs row, so a scan item
// whose name misses MenuScanApplier::normalize() (lowercase + trim only) but
// whose Str::slug() collides with an existing row hits the allocator's retry
// path with the scan's transaction open. Pre-fix that INSERT raised, which on
// Postgres aborts the whole transaction (25P02) — every later statement in the
// apply fails and the enrichment rolls back (a 500 on this endpoint, a
// swallowed-and-logged no-op inside MenuFetchJob). SQLite aborts only the
// statement, so this test can only prove the enrichment lands and the slug
// resolves; it cannot reproduce the Postgres abort.

it('commits a scan apply whose new dish collides with an existing slug base', function () {
    $user = menuUser('scanslugclash');

    // "Café Latte" holds the `cafe-latte` slug (Str::slug transliterates the
    // accent away), but MenuScanApplier's matcher compares raw lowercased
    // names — "café latte" ≠ "cafe latte" — so the scan item below is a NEW
    // dish that wants an already-taken base.
    seedMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Drinks', 'items' => [['name' => 'Café Latte', 'base_price' => 5.0]]],
    ]);
    $existing = MenuItem::query()->where('name', 'Café Latte')->firstOrFail();
    $existingRow = menuSlugRow($user, $existing->id);
    expect($existingRow?->slug)->toBe('cafe-latte');

    $levels = [];
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $e) use (&$levels) {
        $levels[] = $e->connection->transactionLevel();
    });

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [
            ['name' => 'Cafe Latte', 'description' => 'House blend.', 'price' => 5.5, 'category' => 'Specials'],
            // A SECOND item processed after the colliding one — on Postgres an
            // aborted transaction fails every later statement, so this landing
            // is the part that proves the transaction survived the collision.
            ['name' => 'Blueberry Muffin', 'description' => null, 'price' => 4.0, 'category' => 'Specials'],
        ],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 0, 'added' => 2]);

    $minted = MenuItem::query()->where('name', 'Cafe Latte')->firstOrFail();
    expect($minted->description)->toBe('House blend.');
    expect(menuSlugRow($user, $minted->id)?->slug)->toBe('cafe-latte-2');

    // The pivot attach runs AFTER the colliding MenuItem::create in the same
    // transaction — its category membership landing is the second proof.
    $scanCategory = MenuCategory::query()->where('name', 'Specials')->where('source_platform', 'scan')->firstOrFail();
    expect($minted->categories()->pluck('site.menu_categories.id')->all())->toBe([(string) $scanCategory->id]);

    $muffin = MenuItem::query()->where('name', 'Blueberry Muffin')->firstOrFail();
    expect(menuSlugRow($user, $muffin->id)?->slug)->toBe('blueberry-muffin');

    // The incumbent keeps its row and its bare base — the newcomer took -2.
    $existingAfter = menuSlugRow($user, $existing->id);
    expect($existingAfter->id)->toBe($existingRow->id);
    expect($existingAfter->slug)->toBe('cafe-latte');

    // The one assertion here that DOES fail pre-fix on SQLite: each mint ran at
    // transaction level 2 — nested inside MenuScanApplier's own level-1
    // transaction, i.e. behind a SAVEPOINT. Everything above passes either way,
    // because SQLite aborts only the failed statement.
    expect(array_count_values($levels)[2] ?? 0)->toBe(2);
});
