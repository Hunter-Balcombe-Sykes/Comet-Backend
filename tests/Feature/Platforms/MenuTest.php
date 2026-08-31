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
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuProjectionMapper;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Slice 7 Task 5: MenuPayloadComposer reads content.* for the dishes and
    // only falls back to site.menu_* when that lane holds nothing for the
    // owner. The tables have to EXIST for the fallback to be reachable — the
    // scrape still writes site.menu_* until Task 7 moves it.
    setupContentTables();
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
function menuSurfaceFor(string $url): string
{
    // Mirror what routing stamps at connect time: the brand surface matching
    // the URL host. Pre-D7 these helpers hardcoded uber_eats.order for every
    // URL and leaned on host re-derivation; MenuSource now trusts the surface
    // first (D7, 2026-08-26), so the fixture must carry the truthful one.
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    return match (true) {
        str_contains($host, 'doordash') => 'doordash.order',
        str_contains($host, 'menulog') => 'menulog.order',
        str_contains($host, 'square') => 'square.order',
        default => 'uber_eats.order',
    };
}

function ordering(User $user, string $url, ?string $type, string $at): IntegrationConnection
{
    // Since 2026-08-18 the observer dispatches MenuFetchJob for any ordering
    // row on a menu-platform host (F17). These tests drive MenuFetchJob by
    // hand, so keep the observer's dispatch off the sync queue.
    Queue::fake([MenuFetchJob::class]);
    Carbon::setTestNow($at);
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => menuSurfaceFor($url),
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

/**
 * seedMenu()'s content-lane twin, for the tests whose WRITER has already moved
 * (slice 7 Task 8: MenuScanApplier). Lands each dish through the projection
 * mapper, exactly as MenuFetchJob's persist will once Task 7 lands, so its
 * categories carry the plain `menu:<slug>` refs that make them scraper-owned.
 */
function seedContentMenu(User $user, array $menuAttrs, array $categories): Menu
{
    $menu = Menu::create(array_merge([
        'user_id' => $user->id,
        'content_source' => 'uber-eats',
        'currency' => 'AUD',
        'fetch_status' => 'ok',
    ], $menuAttrs));

    $writer = app(ManualMenuWriter::class);

    // Store-card sidecars, keyed by platform: MenuFetchJob::syncOrderPlatforms()
    // writes one per site.menu_platform_links row, and ManualMenuItems::
    // platforms() recovers a dish's per-platform attribution by matching the
    // offer URL's HOST against it. Seeding dishes without it silently drops
    // every per-platform price.
    $storeUrls = [];

    foreach ($categories as $ci => $category) {
        foreach (($category['items'] ?? []) as $item) {
            $platforms = $item['platforms'] ?? [];
            unset($item['platforms']);

            foreach ($platforms as $p) {
                $storeUrls[$p['platform']] ??= $p['storeUrl'] ?? null;
            }
            $writer->write(
                (string) $user->id,
                $writer->coordFor((string) $menu->id, (string) $item['name']),
                $writer->projectionFor(
                    (object) $item,
                    [['id' => (string) Str::uuid(), 'name' => $category['name'], 'position' => $ci]],
                    array_map(fn (array $p) => (object) [
                        'platform' => $p['platform'],
                        'pickup_price' => $p['pickupPrice'] ?? null,
                        'delivery_price' => $p['deliveryPrice'] ?? null,
                        'item_url' => $p['itemUrl'] ?? null,
                        'external_ref' => $p['externalId'] ?? null,
                        'sold_out' => $p['soldOut'] ?? null,
                    ], $platforms),
                    $menu,
                ),
            );
        }
    }

    foreach ($storeUrls as $platform => $storeUrl) {
        seedOrderPlatformSidecar((string) $user->id, (string) $platform, $storeUrl);
    }

    return $menu;
}

/** Every content-lane dish for an owner, keyed by headline. */
function menuContentRows(User $user)
{
    return app(ManualMenuItems::class)->rows((string) $user->id)->keyBy('headline');
}

/** The owner's content-lane menu categories, keyed by label. */
function menuContentCategories(User $user)
{
    return app(ManualMenuItems::class)->categories((string) $user->id)->keyBy('label');
}

/**
 * Slice 7 Task 7 read helpers. MenuFetchJob lands the scrape in content.* now,
 * so the assertions below read it back through ManualMenuItems — the same fold
 * the dashboard uses, which is what makes "the scrape wrote it" and "the
 * dashboard can see it" one assertion instead of two.
 *
 * @return Collection<string, stdClass> normalized name => folded dish
 */
function menuContentDishes(User $user, bool $includeRemoved = false): Collection
{
    return app(ManualMenuItems::class)->rows((string) $user->id, $includeRemoved)
        ->keyBy(fn (stdClass $row) => (string) $row->headline);
}

function menuContentDish(User $user, string $name, bool $includeRemoved = false): ?stdClass
{
    return menuContentDishes($user, $includeRemoved)->get($name);
}

/** @return list<string> category labels in the order the dashboard renders them */
function menuContentCategoryLabels(User $user): array
{
    return app(ManualMenuItems::class)->categories((string) $user->id)
        ->pluck('label')->map(fn ($l) => (string) $l)->all();
}

/** Dish names grouped under each content.* category, in category order. */
function menuContentStructure(User $user): array
{
    $dishes = menuContentDishes($user);
    $out = [];
    foreach (app(ManualMenuItems::class)->categories((string) $user->id) as $category) {
        $out[(string) $category->label] = $dishes
            ->filter(fn (stdClass $row) => in_array((string) $category->id, $row->category_ids, true))
            ->keys()->all();
    }

    return $out;
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

    // Slice 7 Task 7: the dishes land in content.*, and site.menu_* is not
    // touched at all — this pair is the whole point of the task.
    expect(menuContentCategoryLabels($user))->toBe(['Pizzas']);
    $item = menuContentDish($user, 'Margherita');
    expect($item)->not->toBeNull();
    expect($item->base_price)->toBe(12.5);
    // Image set persists hero-first alongside the single image_url.
    expect($item->image_url)->toBe('https://ue/marg.jpg');
    expect($item->images)->toBe(['https://ue/marg.jpg']);

    expect(MenuItem::query()->where('menu_id', $menu->id)->exists())->toBeFalse();
    expect(MenuCategory::query()->where('menu_id', $menu->id)->exists())->toBeFalse();
    expect(MenuItemPlatform::query()->count())->toBe(0);
});

it('lands the scrape on the coord MenuProjectionMapper derives, with the store card beside it', function () {
    // The two structural halves Task 7 owes the read side: the coord (identity
    // across rebuilds AND the key slice 4's backfill already used for the 318
    // live dishes) and the order_platform storefront (the ONLY thing that
    // re-pairs a dish's deep link with its platform — ManualMenuItems matches
    // by URL host against it).
    $user = menuUser('m6coord');
    ordering($user, 'https://www.ubereats.com/store/coord', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5]]]],
        ]]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect(menuContentDish($user, 'Margherita')->coord)
        ->toBe(MenuProjectionMapper::coordFor((string) $menu->id, 'Margherita'));

    $storefront = DB::connection('pgsql')->table('content.collections as c')
        ->join('content.storefronts as sf', 'sf.collection_id', '=', 'c.id')
        ->where('c.user_id', $user->id)->where('c.kind', 'order_platform')
        ->first(['c.external_ref', 'sf.provider', 'sf.url']);
    expect($storefront)->not->toBeNull();
    expect($storefront->external_ref)->toBe(MenuProjectionMapper::orderPlatformRef('uber-eats'));
    expect($storefront->provider)->toBe('uber-eats');
    expect($storefront->url)->toBe('https://www.ubereats.com/store/coord');
});

it('seeds a pool:menus pin for a dish that has none and never rewrites one the owner set', function () {
    // Dish ORDER lives in the pins, not in content.collection_items.position
    // (ProvisionMenuPinsCommand's docblock, and MenuPayloadComposer::pinOrder()
    // is the reader). An unpinned dish trails every pinned one, so a scrape that
    // pinned nothing would render a brand-new menu alphabetically. Seeding is
    // therefore required; REWRITING is forbidden — a scheduled run must never
    // snap an owner's reorder back (parent §19).
    setupSectionsTables();
    $user = menuUser('m6pin');
    // A pin hangs off the owner's pool:menus section, so this needs a real site.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => 'm6pin',
        'is_published' => 1, 'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    ordering($user, 'https://www.ubereats.com/store/pin', null, '2026-06-17 10:00:00');

    $run1 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Steak', 'pickupPrice' => 30.0, 'deliveryPrice' => 30.0],
            ['name' => 'Fries', 'pickupPrice' => 6.0, 'deliveryPrice' => 6.0],
        ]]],
    ]];
    $run2 = $run1;
    $run2['uber-eats']['categories'][0]['items'][] = ['name' => 'Pie', 'pickupPrice' => 9.0, 'deliveryPrice' => 9.0];

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->twice()->andReturn($run1, $run2));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $pins = fn () => DB::connection('pgsql')->table('site.section_items as si')
        ->join('content.items as i', 'i.id', '=', 'si.item_id')
        ->orderBy('si.sort_key')->pluck('i.headline_cache')->all();

    // The vendor's order, laid down 1..N on the first run.
    expect($pins())->toBe(['Steak', 'Fries']);

    // The owner drags Fries to the front.
    DB::connection('pgsql')->table('site.section_items')
        ->where('item_id', menuContentDish($user, 'Fries')->id)->update(['sort_key' => 0.5]);

    (new MenuFetchJob((string) $user->id, force: true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // The drag survives, and the new dish appends after it rather than
    // displacing anything.
    expect($pins())->toBe(['Fries', 'Steak', 'Pie']);
});

it('writes one storefront per menu_platform_links row so a two-platform dish keeps both order links', function () {
    $user = menuUser('m6sf2');
    ordering($user, 'https://www.ubereats.com/store/two', null, '2026-06-17 09:00:00');
    ordering($user, 'https://www.doordash.com/store/two/', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn([
            'uber-eats' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [['name' => 'Mains', 'items' => [['name' => 'Burrito', 'pickupPrice' => 17.0, 'deliveryPrice' => 17.0]]]],
            ],
            'doordash' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [['name' => 'Mains', 'items' => [['name' => 'Burrito', 'pickupPrice' => 15.5, 'deliveryPrice' => 15.5]]]],
            ],
        ]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $providers = DB::connection('pgsql')->table('content.collections as c')
        ->join('content.storefronts as sf', 'sf.collection_id', '=', 'c.id')
        ->where('c.user_id', $user->id)->where('c.kind', 'order_platform')
        ->orderBy('sf.provider')->pluck('sf.provider')->all();
    expect($providers)->toBe(['doordash', 'uber-eats']);

    // Stored attribution (offers.platform, 2026-08-26) — both entries carry
    // their own platform's prices; per-dish store urls are retired (D1), and
    // this scrape emitted no per-item links, so item_url stays null rather
    // than falling back to a store link.
    $platforms = collect(menuContentDish($user, 'Burrito')->platforms)->keyBy('platform');
    expect($platforms->keys()->sort()->values()->all())->toBe(['doordash', 'uber-eats']);
    expect($platforms['uber-eats']->pickup_price)->toBe(17.0);
    expect($platforms['doordash']->pickup_price)->toBe(15.5);
    expect($platforms['uber-eats']->item_url)->toBeNull();
    expect($platforms['doordash']->item_url)->toBeNull();
});

it('reuses category and item ids across rebuilds when names match (stable identity)', function () {
    // Popularity scores (analytics item_id) and sitepage item-detail URLs both
    // key on the dish id across refreshes, so a refresh must land on the SAME
    // row for a dish that still exists. Slice 7: the identity is no longer
    // reconstructed by a reuse pool — the coord IS the normalized name, so an
    // upsert-by-coord gives it for free. A dish the vendor drops is marked
    // removed (never hard-deleted) and a new dish mints a fresh id.
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
    $idsBefore = menuContentDishes($user)->map(fn (stdClass $row) => (string) $row->id);
    $pizzasIdBefore = app(ManualMenuItems::class)->categories((string) $user->id)
        ->firstWhere('label', 'Pizzas')->id;

    // Forced refresh (the unchanged-skip gate would otherwise no-op the rebuild).
    (new MenuFetchJob((string) $user->id, force: true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $idsAfter = menuContentDishes($user)->map(fn (stdClass $row) => (string) $row->id);
    expect($idsAfter['Margherita'])->toBe($idsBefore['Margherita']);
    expect($idsAfter['Fries'])->toBe($idsBefore['Fries']);
    // The refreshed price landed on the SAME row (a real update, not a lookalike).
    expect(menuContentDish($user, 'Margherita')->base_price)->toBe(13.0);
    // The dropped dish is marked removed — NOT hard-deleted, and its id was
    // not silently re-assigned to the new dish.
    expect($idsAfter)->not->toHaveKey('Pepperoni');
    expect(menuContentDish($user, 'Pepperoni', includeRemoved: true)->removed_at)->not->toBeNull();
    expect($idsAfter['Calzone'])->not->toBe($idsBefore['Pepperoni']);
    // Categories keep their identity through the upstream reorder too.
    expect((string) app(ManualMenuItems::class)->categories((string) $user->id)
        ->firstWhere('label', 'Pizzas')->id)->toBe((string) $pizzasIdBefore);
});

it('collapses a same-named dish in two categories into ONE item with BOTH memberships, id-stable across rebuilds', function () {
    // Multi-category model (2026-07-21): "Coke" under Drinks AND Combos is a
    // single dish with two category memberships — the duplicate-rows problem
    // this redesign removes. Slice 7 keeps it by deduping menu-wide BEFORE the
    // write: writeManualItem() replaces an item's memberships per source, so
    // writing the coord twice would leave Coke in whichever category came last.
    // Its id must survive forced rebuilds (popularity scores + item URLs key on it).
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

    expect(menuContentDishes($user))->toHaveCount(1);
    $cokeCategories = function () use ($user) {
        $labels = menuContentDish($user, 'Coke')->category_labels;
        sort($labels);

        return array_values($labels);
    };
    expect($cokeCategories())->toBe(['Combos', 'Drinks']);
    // First occurrence (Drinks) wins the display fields.
    expect(menuContentDish($user, 'Coke')->pickup_price)->toBe(4.0);
    $idBefore = (string) menuContentDish($user, 'Coke')->id;

    (new MenuFetchJob((string) $user->id, force: true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect((string) menuContentDish($user, 'Coke')->id)->toBe($idBefore);
    expect($cokeCategories())->toBe(['Combos', 'Drinks']);

    (new MenuFetchJob((string) $user->id, force: true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    expect((string) menuContentDish($user, 'Coke')->id)->toBe($idBefore);
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
    expect(menuContentDish($user, 'Margherita'))->not->toBeNull();
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
    expect(menuContentCategoryLabels($user))->toBe(['Fresh']);
    // The pre-existing legacy 'Old' category is not touched by the scrape any
    // more — Phase 5 drops that table; the scrape's own lane is content.*.
    expect(menuContentDish($user, 'New'))->not->toBeNull();
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

    // The slug reconcile (271-DINT-1) hangs off persist() too, so the same
    // ordering guarantee has to hold for it: a failed rebuild must not free or
    // re-mint a single slug. Phase 6: the registry is content.item_slugs —
    // site.item_slugs and its legacy dish rows retired with the menu tables.
    $priorSlugs = DB::connection('pgsql')->table('content.item_slugs')->orderBy('item_id')->get()->toArray();

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
        protected function persist(Menu $menu, string $contentSource, array $merged, Carbon $now, array $failedPlatforms = []): void
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
    expect(DB::connection('pgsql')->table('content.item_slugs')->orderBy('item_id')->get()->toArray())->toEqual($priorSlugs);
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

// ── DINT-102: a menu with live categories can never be SOFT-deleted ───────
// MenuFetchJob::clearScrapedContent() only ever calls $menu->delete() after
// checking `! $menu->categories()->exists()` — this MenuObserver makes that
// invariant a model-level guarantee (not just a convention at one call site)
// so a future delete path can't leave a category tree under a menu the user
// can no longer see.
//
// NARROWED (Nightwatch #297): the guard no longer covers the retention
// hard-delete. All three children are ON DELETE CASCADE, so forceDelete()
// cannot orphan anything, and guarding it jammed PurgeSoftDeleted forever —
// MenuCategory has no SoftDeletes, so categories()->exists() never goes false
// for a soft-deleted menu. See MenuObserver's docblock for the FK evidence.

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
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
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
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
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
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
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
    $menu = seedContentMenu($user, ['content_source' => 'uber-eats', 'rating' => 4.7], [
        ['name' => 'Pizzas', 'items' => [[
            'name' => 'Margherita', 'base_price' => 11.0,
            'delivery_price' => 12.5,             'pickup_price' => 11.0,             'rating' => 95, 'badges' => [['text' => '#1 Most liked']],
            'platforms' => [
                ['platform' => 'uber-eats', 'pickupPrice' => null, 'deliveryPrice' => 12.5, 'itemUrl' => 'https://www.ubereats.com/store/d/sec/sub/u1', 'externalId' => 'u1', 'soldOut' => null],
                ['platform' => 'doordash', 'pickupPrice' => 11.0, 'deliveryPrice' => null, 'itemUrl' => 'https://www.doordash.com/store/x?itemId=d1', 'externalId' => 'd1', 'soldOut' => false],
            ],
        ]]],
    ]);

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();

    expect($res->json('source'))->toBe('uber-eats');
    expect((float) $res->json('rating'))->toBe(4.7);
    // Category carries its persisted id (addresses PATCH/DELETE .../categories/{id}).
    // Phase 6: that id is the content.collections id, and sourcePlatform is a
    // documented LOSS — content.collections carries no source column, so the
    // dashboard's sync-detach warning has nothing to key off.
    $contentCategory = menuContentCategories($user)['Pizzas'];
    expect($res->json('categories.0.id'))->toBe((string) $contentCategory->id);
    expect($res->json('categories.0.sourcePlatform'))->toBeNull();
    $item = $res->json('categories.0.items.0');
    expect($item['name'])->toBe('Margherita');
    // Stable persisted id (fix-round P1) — mirrors the `id` field the menus
    // pool emits; Partna-Frontend's menu-item-detail URLs key off THIS
    // endpoint's id, not the public sitepage payload's.
    $contentItem = menuContentRows($user)['Margherita'];
    expect($item['id'])->toBe((string) $contentItem->id);
    // pickupSource is one of ManualMenuItems' documented nulls — the
    // projection has no target for it (composer docblock). isManual keeps its
    // legacy false for a scraped dish.
    expect($item['isManual'])->toBeFalse();
    expect((float) $item['basePrice'])->toBe(11.0);
    expect((float) $item['pickupPrice'])->toBe(11.0);
    expect((float) $item['deliveryPrice'])->toBe(12.5);
    expect((float) $item['rating'])->toBe(95.0);
    expect($item['badges'][0]['text'])->toBe('#1 Most liked');
    // Per-platform availability surfaces per-mode prices + the dish's own
    // identity there (D1, 2026-08-26 — per-dish store urls retired).
    expect($item['platforms'])->toHaveCount(2);
    expect($item['platforms'][0]['platform'])->toBe('uber-eats');
    expect((float) $item['platforms'][0]['deliveryPrice'])->toBe(12.5);
    expect($item['platforms'][0]['itemUrl'])->toBe('https://www.ubereats.com/store/d/sec/sub/u1');
    expect($item['platforms'][0]['externalRef'])->toBe('u1');
    expect($item['platforms'][0]['pickupPrice'])->toBeNull();
    expect($item['platforms'][1]['platform'])->toBe('doordash');
    expect((float) $item['platforms'][1]['pickupPrice'])->toBe(11.0);
    expect($item['platforms'][1]['deliveryPrice'])->toBeNull();
    expect($item['platforms'][1]['soldOut'])->toBeFalse();
    // The dish-level links map carries the stored per-item deep links.
    expect($item['links']['uber_eats'])->toBe('https://www.ubereats.com/store/d/sec/sub/u1');
    expect($item['links']['doordash'])->toBe('https://www.doordash.com/store/x?itemId=d1');
    // Menu-level order links (store CTAs) are unchanged by D1.
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
    expect(menuContentDish($user, 'Margherita')->currency)->toBe('AUD');

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
    // created_at has to be aged too, now that the window bound no longer reads
    // last_fetched_at: that column advances on every FAILED attempt, so it can
    // never expire and the "aged out" this fixture asserts never happened in
    // production (see RetryUnavailableMenusCommand). The bound is
    // last_successful_fetch_at, falling back to the menu's own age when a menu
    // has never once succeeded — and a menu whose last attempt was 12h ago was
    // not created a millisecond ago, so the fixture owed itself this line.
    $stale = menuUser('m18');
    $staleMenu = Menu::create(['user_id' => $stale->id, 'content_source' => 'doordash', 'last_fetched_at' => now()->subHours(12)]);
    Menu::query()->whereKey($staleMenu->id)->update(['created_at' => now()->subHours(12)]);
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

    // The shared dish: one row, both platforms, gap-filled description from DD.
    $burrito = menuContentDish($user, 'Burrito');
    expect($burrito->base_price)->toBe(15.5);                 // min across platforms
    expect($burrito->pickup_price)->toBe(15.5);              // DoorDash offers pickup
    expect($burrito->delivery_price)->toBe(17.0);            // Uber Eats offers delivery
    expect($burrito->description)->toBe('Loaded burrito.');  // gap-filled from DD
    expect($burrito->image_url)->toBe('https://ue/b.jpg');   // UE image preferred
    // pickup_source / delivery_source (which platform backed the aggregate min)
    // have no projection target — MenuProjectionMapper never carried them and
    // ManualMenuItems documents them as unrecoverable. The per-platform prices
    // below still say the same thing.
    $platforms = collect($burrito->platforms)->keyBy('platform');
    expect($platforms)->toHaveCount(2);
    expect($platforms->keys()->all())->toBe(['uber-eats', 'doordash']);
    expect($platforms['uber-eats']->delivery_price)->toBe(17.0);
    expect($platforms['uber-eats']->pickup_price)->toBeNull();
    expect($platforms['doordash']->pickup_price)->toBe(15.5);
    expect($platforms['doordash']->delivery_price)->toBeNull();

    // The DoorDash-only dish appears (not dropped) with a single-platform entry.
    $churros = collect(menuContentDish($user, 'Churros')->platforms);
    expect($churros)->toHaveCount(1);
    expect($churros->first()->platform)->toBe('doordash');
    expect($churros->first()->pickup_price)->toBe(8.0);
    expect($churros->first()->delivery_price)->toBeNull();
});

// ── Online-ordering store consolidation (one store = one entry) ────────

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
    Workplace::forceCreate([
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
    // Category-grouped dish names in display order — the structure the readers
    // render, now read back out of content.*.
    $platformEntries = fn () => menuContentDishes($user)->sum(fn (stdClass $row) => count($row->platforms));
    $cats1 = menuContentCategoryLabels($user);
    $items1 = menuContentStructure($user);
    $linkCount1 = $platformEntries();

    // Run #2 — identical scraper output, forced rebuild.
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->once()->andReturn($scraperOutput));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu->refresh();

    expect(menuContentCategoryLabels($user))->toBe($cats1);
    expect(menuContentStructure($user))->toBe($items1);
    expect($platformEntries())->toBe($linkCount1);
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
                ['name' => 'Chef Specials', 'items' => [['name' => 'Hero Dish', 'pickupPrice' => 10.0, 'deliveryPrice' => 10.0]]],
                ['name' => 'Mains', 'items' => [['name' => 'Steak', 'pickupPrice' => 30.0, 'deliveryPrice' => 30.0]]],
                ['name' => 'Sides', 'items' => [['name' => 'Fries', 'pickupPrice' => 6.0, 'deliveryPrice' => 6.0]]],
            ],
        ],
    ];
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->once()->andReturn($run1Output));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $categoryPositions = fn () => app(ManualMenuItems::class)->categories((string) $user->id)
        ->pluck('label', 'position')->map(fn ($l) => (string) $l)->all();
    $positions1 = $categoryPositions();
    expect($positions1)->toBe([0 => 'Chef Specials', 1 => 'Mains', 2 => 'Sides']);

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

    expect($categoryPositions())->toBe($positions1);
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
    Workplace::forceCreate([
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

    expect($res->json())->toBe(['updated' => 0, 'added' => 1, 'skipped' => 0]);
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->content_source)->toBe('scan');
    expect($menu->fetch_status)->toBe('ok');
    expect($menu->last_fetched_at)->not->toBeNull();

    // The 'scan' tag lives in the category's external_ref namespace since slice
    // 7 Task 8 — content.collections carries no source column.
    $category = menuContentCategories($user)['Pizzas'];
    expect((string) $category->external_ref)->toBe(MenuScanApplier::categoryRefFor('scan', 'Pizzas'));

    $item = menuContentRows($user)['Margherita Pizza'];
    expect($item->description)->toBe('Classic.');
    expect((float) $item->base_price)->toBe(14.5);
    expect($item->category_ids)->toBe([(string) $category->id]);
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

    $labels = array_map(fn ($b) => $b['text'] ?? null, (array) menuContentRows($user)['Falafel Bowl']->badges);
    expect($labels)->toContain('Vegan');
    expect($labels)->toContain('Gluten free');
});

it('defaults new scan items with no category to the last-sorted "More" bucket', function () {
    // B5/3b (2026-08-26): 'More', not 'Menu' — a scan wrapper must never
    // become a display category named after the scan.
    $user = menuUser('scan2');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Mystery Dish', 'description' => null, 'price' => null, 'category' => null]],
    ])->assertOk()->assertExactJson(['updated' => 0, 'added' => 1, 'skipped' => 0]);

    $category = menuContentCategories($user)['More'];
    expect((string) $category->external_ref)->toBe(MenuScanApplier::categoryRefFor('scan', 'More'));
    $item = menuContentRows($user)['Mystery Dish'];
    expect($item->base_price)->toBeNull();
    expect($item->category_ids)->toBe([(string) $category->id]);
});

it('updates an existing item by case-insensitive trimmed name match without nulling missing fields', function () {
    $user = menuUser('scan3');
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Chicken Parma', 'description' => 'Original.', 'base_price' => 18.0]]],
    ]);

    // Scan sends a case/whitespace-different name, a new price, no description.
    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => '  chicken parma  ', 'description' => null, 'price' => 19.5, 'category' => null]],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 0, 'skipped' => 0]);
    $item = menuContentRows($user)['Chicken Parma'];
    expect($item->headline)->toBe('Chicken Parma');     // display name untouched
    expect($item->description)->toBe('Original.');      // NOT null'd out — scan omitted it
    expect((float) $item->base_price)->toBe(19.5);      // price updated
    expect(menuContentCategories($user))->toHaveCount(1); // no new category
});

it('updates description when the scan provides it and leaves price alone when the scan omits it', function () {
    $user = menuUser('scan4');
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Butter Chicken', 'description' => 'Old desc.', 'base_price' => 20.0]]],
    ]);

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Butter Chicken', 'description' => 'Creamy tomato curry.', 'price' => null, 'category' => null]],
    ])->assertOk()->assertExactJson(['updated' => 1, 'added' => 0, 'skipped' => 0]);

    $item = menuContentRows($user)['Butter Chicken'];
    expect($item->description)->toBe('Creamy tomato curry.');
    expect((float) $item->base_price)->toBe(20.0); // untouched — scan omitted price
});

it('reports mixed updated/added counts for a batch with both matches and new items', function () {
    $user = menuUser('scan5');
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Burger', 'base_price' => 15.0]]],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [
            ['name' => 'Burger', 'description' => 'Beef patty.', 'price' => 16.0, 'category' => null],
            ['name' => 'Fries', 'description' => null, 'price' => 6.0, 'category' => 'Sides'],
            ['name' => 'Cola', 'description' => null, 'price' => 4.0, 'category' => 'Sides'],
        ],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 2, 'skipped' => 0]);
    expect(menuContentRows($user))->toHaveCount(3);
    // Both new items land in the SAME new "Sides" scan category, not two.
    $sides = menuContentCategories($user)['Sides'];
    expect((string) $sides->external_ref)->toBe(MenuScanApplier::categoryRefFor('scan', 'Sides'));
    expect(menuContentRows($user)->filter(fn ($r) => in_array((string) $sides->id, $r->category_ids, true)))
        ->toHaveCount(2);
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
    // The one Garlic Bread dish is listed under BOTH scraped sections.
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD', 'fetch_status' => 'ok']);
    $writer = app(ManualMenuWriter::class);
    $writer->write((string) $user->id, $writer->coordFor((string) $menu->id, 'Garlic Bread'), $writer->projectionFor(
        (object) ['name' => 'Garlic Bread', 'base_price' => 8.0],
        [
            ['id' => (string) Str::uuid(), 'name' => 'Starters', 'position' => 0],
            ['id' => (string) Str::uuid(), 'name' => 'Sides', 'position' => 1],
        ],
        [],
        $menu,
    ));

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Garlic Bread', 'description' => 'Toasted, buttery.', 'price' => 6.5, 'category' => 'sides']],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 0, 'skipped' => 0]);
    $garlic = menuContentRows($user)['Garlic Bread'];
    expect((float) $garlic->base_price)->toBe(6.5);
    expect($garlic->description)->toBe('Toasted, buttery.');
    // 'sides' was already covered by the scraped "Sides" membership — no
    // scan-owned duplicate category may appear.
    expect(menuContentCategories($user))->toHaveCount(2);
    expect($garlic->category_ids)->toHaveCount(2);
});

it('attaches the scan category to a matched dish that is not listed under it yet', function () {
    $user = menuUser('scan16');
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Starters', 'items' => [['name' => 'Garlic Bread', 'base_price' => 8.0]]],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Garlic Bread', 'description' => null, 'price' => 7.0, 'category' => 'Dinner']],
    ])->assertOk();

    // One row still — the scan grew its memberships instead of duplicating it.
    expect($res->json())->toBe(['updated' => 1, 'added' => 0, 'skipped' => 0]);
    expect(menuContentRows($user))->toHaveCount(1);

    $garlic = menuContentRows($user)['Garlic Bread'];
    $labels = menuContentCategories($user)->keyBy('id');
    expect((float) $garlic->base_price)->toBe(7.0);
    expect(collect($garlic->category_ids)->map(fn ($id) => (string) $labels[$id]->label)->sort()->values()->all())
        ->toBe(['Dinner', 'Starters']);
    expect((string) menuContentCategories($user)['Dinner']->external_ref)
        ->toBe(MenuScanApplier::categoryRefFor('scan', 'Dinner'));
});

it('still updates by name alone when exactly one item shares that name and the scan supplies no category (pinned existing behavior)', function () {
    $user = menuUser('scan17');
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Mains', 'items' => [['name' => 'Caesar Salad', 'base_price' => 12.0]]],
    ]);

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'caesar salad', 'description' => 'With anchovies.', 'price' => 13.0, 'category' => null]],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 1, 'added' => 0, 'skipped' => 0]);
    $item = menuContentRows($user)['Caesar Salad'];
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

    expect($res->json())->toBe(['updated' => 0, 'added' => 2, 'skipped' => 0]);
    $rows = menuContentRows($user);
    // A hand-entered zero projects to a `free` qualifier, which reads back as
    // 0.0 rather than null (ManualMenuItems::amount) — the same distinction the
    // nullable legacy column carried.
    expect((float) $rows['Free Tasting']->base_price)->toBe(0.0);
    expect((float) $rows['Banquet Buyout']->base_price)->toBe(99999.99);
});

// #W1-SEC-9: description/category were nullable|string with no cap — up to
// 200 items/request, each carrying an unbounded text field.
it('422s scan apply for a description over the sane upper bound', function () {
    $user = menuUser('scan8e');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Item', 'description' => str_repeat('a', MenuAiExtractor::DESCRIPTION_MAX + 1), 'price' => null, 'category' => null]],
    ])->assertStatus(422);
});

it('422s scan apply for a category over the sane upper bound', function () {
    $user = menuUser('scan8f');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Item', 'description' => null, 'price' => null, 'category' => str_repeat('a', 161)]],
    ])->assertStatus(422);
});

it('scan apply accepts description/category at exactly the cap', function () {
    $user = menuUser('scan8g');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Item', 'description' => str_repeat('a', MenuAiExtractor::DESCRIPTION_MAX), 'price' => null, 'category' => str_repeat('b', 160)]],
    ])->assertOk();
});

// review round 2, #W1-SEC-9: the category cap must match
// MenuAiExtractor::NAME_MAX (160), not an independently-invented 100 — the
// extractor legitimately truncates+emits categories up to 160 chars, so a
// tighter validator cap stranded an accepted scan on /scan/apply.
it('accepts a scanned category between 101 and 160 characters (matches MenuAiExtractor::NAME_MAX)', function () {
    $user = menuUser('scan8h');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Item', 'description' => null, 'price' => null, 'category' => str_repeat('c', 140)]],
    ])->assertOk();
});

// ── BE3: refresh-survival — scan content must outlive a scraper rebuild ──
// MenuFetchJob wholesale-deletes+reinserts menu_categories/menu_items on
// every real scrape (persist()) and on losing the last ordering link
// (handle()'s early-return). Both paths must skip source_platform='scan'
// rows or a user's scanned menu silently vanishes on the next refresh.

it('preserves scan-sourced categories and items across a forced scraper rebuild (refresh-survival)', function () {
    $user = menuUser('scan9');
    ordering($user, 'https://www.ubereats.com/store/scanguard', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->twice()->andReturn(
            ['uber-eats' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [['name' => 'Mains', 'items' => [['name' => 'Old Scraped Dish', 'pickupPrice' => 10.0, 'deliveryPrice' => 10.0]]]],
            ]],
            ['uber-eats' => [
                'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
                'categories' => [['name' => 'Fresh', 'items' => [['name' => 'New Scraped Dish', 'pickupPrice' => 9.0, 'deliveryPrice' => 9.0]]]],
            ]],
        );
    });
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Special Lasagna', 'description' => 'Family recipe.', 'price' => 22.0, 'category' => 'Specials']],
    ])->assertOk();

    // Since slice 7 Task 8 the scan writes content.*, so its survival is
    // structural rather than a source_platform filter: nothing the scrape
    // rebuilds can reach the `menu:scan:*` external_ref namespace.
    $scanCategory = menuContentCategories($user)['Specials'];
    expect((string) $scanCategory->external_ref)->toBe(MenuScanApplier::categoryRefFor('scan', 'Specials'));
    // Task 8 moved the scan lane to content.* too, so the scan dish is
    // resolved there rather than off site.menu_*. What this test pins is
    // unchanged: the scrape does not reach into scan-owned content.
    $scanItem = menuContentDish($user, 'Special Lasagna');
    expect($scanItem)->not->toBeNull();

    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // The dish the vendor dropped is marked removed, not hard-deleted...
    expect(menuContentDish($user, 'Old Scraped Dish'))->toBeNull();
    expect(menuContentDish($user, 'Old Scraped Dish', includeRemoved: true)->removed_at)->not->toBeNull();
    expect(menuContentDish($user, 'New Scraped Dish'))->not->toBeNull();

    // ...but the scan-sourced category + item survived, untouched.
    expect(menuContentCategories($user)->has('Specials'))->toBeTrue();
    // Both the category and the dish are content.* rows since Task 8, so their
    // survival is asserted there — there is no legacy row left to check.
    $scanItem = menuContentRows($user)['Special Lasagna'];
    expect($scanItem->headline)->toBe('Special Lasagna');
    expect((float) $scanItem->base_price)->toBe(22.0);
    expect($scanItem->category_ids)->toBe([(string) $scanCategory->id]);
});

it('never retires a dish the owner deleted, hand-added or had photo-scanned', function () {
    // The three exemptions retireAbsentDishes() owes: menus.suppressed_items
    // (the owner's delete / detach), menus.scan_items (a photo-scan dish the
    // scrape had matched onto) and "no order_platform membership" — which is
    // what makes a hand-added dish invisible to the scrape's write scope, and
    // is the content.* replacement for rebuildableCategoryIds().
    $user = menuUser('scan9b');
    ordering($user, 'https://www.ubereats.com/store/exempt', null, '2026-06-17 10:00:00');

    $full = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Dropped Dish', 'pickupPrice' => 10.0, 'deliveryPrice' => 10.0],
            ['name' => 'Suppressed Dish', 'pickupPrice' => 11.0, 'deliveryPrice' => 11.0],
            ['name' => 'Scanned Dish', 'pickupPrice' => 12.0, 'deliveryPrice' => 12.0],
        ]]],
    ]];
    $empty = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [['name' => 'Survivor', 'pickupPrice' => 9.0, 'deliveryPrice' => 9.0]]]],
    ]];

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->twice()->andReturn($full, $empty));
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    Menu::query()->where('user_id', $user->id)->firstOrFail()->forceFill([
        'suppressed_items' => [['category' => 'Mains', 'name' => 'Suppressed Dish']],
        'scan_items' => ['items' => [['name' => 'Scanned Dish', 'price' => 12.0, 'category' => 'Mains']]],
    ])->save();

    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    $live = menuContentDishes($user)->keys()->sort()->values()->all();
    expect($live)->toBe(['Scanned Dish', 'Suppressed Dish', 'Survivor']);
    expect(menuContentDish($user, 'Dropped Dish', includeRemoved: true)->removed_at)->not->toBeNull();
    // Suppression is a WRITE skip too — the scrape never re-listed it, so its
    // price is still the one from the first run.
    expect(menuContentDish($user, 'Suppressed Dish')->base_price)->toBe(11.0);
});

it('preserves scan-sourced content when the user disconnects their only ordering platform', function () {
    $user = menuUser('scan10');
    ordering($user, 'https://www.ubereats.com/store/scanguard2', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Mains', 'items' => [['name' => 'Scraped Dish', 'pickupPrice' => 10.0, 'deliveryPrice' => 10.0]]]],
        ]]);
    });
    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Scanned Special', 'description' => null, 'price' => 12.0, 'category' => 'Specials']],
    ])->assertOk();
    expect(menuContentRows($user)->has('Scanned Special'))->toBeTrue();

    // Remove the only ordering link — MenuFetchJob now dispatches with no
    // resolvable source at all (resolveAll() returns null).
    IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->delete();

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // Scraped content is gone, but the scan item + the menu row itself survive.
    expect(MenuItem::query()->where('name', 'Scraped Dish')->exists())->toBeFalse();
    expect(menuContentRows($user)->has('Scanned Special'))->toBeTrue();
    // The scraped dish is retired and its store card sidecar dropped, but the
    // scan item + the menu row itself survive.
    expect(menuContentDish($user, 'Scraped Dish'))->toBeNull();
    expect(menuContentDish($user, 'Scraped Dish', includeRemoved: true)->removed_at)->not->toBeNull();
    expect(DB::connection('pgsql')->table('content.storefronts')->count())->toBe(0);
    // The scan dish survives in the CONTENT lane (Task 8); there is no legacy
    // row to assert any more.
    expect(menuContentDish($user, 'Scanned Special'))->not->toBeNull();
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
    expect(menuContentDish($user, 'Original Scraped Dish'))->not->toBeNull();
    expect(MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->exists())->toBeTrue();

    // Scan content that must survive the disconnect.
    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Scanned Special', 'description' => null, 'price' => 12.0, 'category' => 'Specials']],
    ])->assertOk();

    // Disconnect the only ordering link.
    IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->delete();
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // Scraped content + the stale platformLinks row are gone; scan content survives.
    expect(MenuItem::query()->where('name', 'Original Scraped Dish')->exists())->toBeFalse();
    expect(menuContentRows($user)->has('Scanned Special'))->toBeTrue();
    expect(menuContentDish($user, 'Original Scraped Dish'))->toBeNull();
    // Scan content lives in content.* since Task 8 — asserted at :1665 above.
    expect(MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->exists())->toBeFalse();

    // Reconnect the SAME store — must force a real re-scrape, not a silent skip.
    ordering($user, $storeUrl, null, '2026-06-17 11:00:00');
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    expect(menuContentDish($user, 'Post-Reconnect Dish'))->not->toBeNull();
    // The storefront sidecar comes back with the reconnect — the order_platform
    // collection was kept (empty) precisely so the upsert had a row to land on.
    expect(DB::connection('pgsql')->table('content.storefronts')->where('provider', 'uber-eats')->value('url'))
        ->toBe($storeUrl);
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
    // The scraped sibling is seeded in the CONTENT lane: MenuPayloadComposer
    // reads content.* whenever it holds anything for the owner (Task 5's gate),
    // and the scan below puts something there — so a legacy-only scraped dish
    // would simply be invisible to this assertion rather than exercising it.
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
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

// ── 271-DINT-1: the wholesale rebuild must keep item slugs in step ──
// persist() writes items through the query builder (bulk insert + mass
// delete), so pre-fix a scraped dish never got a pretty URL at all, and a
// dropped dish squatted its slug forever (idx_item_slugs_unique is NOT
// partial, so even a retired row blocks reuse). The reconcile still runs
// AFTER the rebuild transaction commits — best-effort per-dish work that must
// not be able to roll the rebuild back — though the allocator is safe inside a
// transaction too (insertOrIgnore behind a savepoint; see
// ContentItemSlugAllocator::insertUnique).

/**
 * Slice 7 Task 7: a scraped dish's permalink lives in content.item_slugs now,
 * minted by ProjectionWriter::refreshItemCaches() and freed by
 * ManualPoolWriter::markRemoved() — MenuFetchJob no longer reconciles
 * site.item_slugs at all (its menu_item lane belongs to the legacy writers
 * until Tasks 6 and 8, then to Phase 5's drop).
 */
function menuContentSlugRow(User $user, string $itemId): ?object
{
    return DB::connection('pgsql')->table('content.item_slugs')
        ->where('user_id', $user->id)->where('item_id', $itemId)->where('is_current', 1)
        ->first(['id', 'slug']);
}

function menuContentSlugCount(User $user, string $itemId): int
{
    return DB::connection('pgsql')->table('content.item_slugs')
        ->where('user_id', $user->id)->where('item_id', $itemId)->count();
}

/** The scraped dish's content item id, by name. */
function menuContentId(User $user, string $name): string
{
    return (string) menuContentDish($user, $name, includeRemoved: true)->id;
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

    expect(menuContentSlugRow($user, menuContentId($user, 'Fish Tacos'))?->slug)->toBe('fish-tacos');
    expect(menuContentSlugRow($user, menuContentId($user, 'Beef Tacos'))?->slug)->toBe('beef-tacos');
});

it('frees a dropped dish slug on the next scrape and leaves the survivor row untouched', function () {
    $user = menuUser('slugdrop');
    ordering($user, 'https://www.ubereats.com/store/slugdrop', null, '2026-06-17 10:00:00');

    mockMenuScrape([['name' => 'Tacos', 'items' => [scrapedDish('Fish Tacos'), scrapedDish('Beef Tacos')]]]);
    runMenuFetch($user);

    $fishId = menuContentId($user, 'Fish Tacos');
    $beefId = menuContentId($user, 'Beef Tacos');
    $fishRow = menuContentSlugRow($user, $fishId);

    // Scrape 2 drops the beef tacos entirely.
    mockMenuScrape([['name' => 'Tacos', 'items' => [scrapedDish('Fish Tacos')]]]);
    runMenuFetch($user);

    // The dropped dish's slug is HARD-deleted (a retired row would still squat
    // the slug — the unique index is not partial). The ITEM survives, marked
    // removed rather than deleted.
    expect(menuContentSlugCount($user, $beefId))->toBe(0);
    expect(menuContentDish($user, 'Beef Tacos', includeRemoved: true)->removed_at)->not->toBeNull();
    // ...and the survivor's row is the SAME row, not a delete-and-remint.
    $fishAfter = menuContentSlugRow($user, $fishId);
    expect($fishAfter->id)->toBe($fishRow->id);
    expect($fishAfter->slug)->toBe('fish-tacos');
});

it('does not churn item_slugs rows when an identical scrape reuses every dish identity', function () {
    $user = menuUser('slugchurn');
    ordering($user, 'https://www.ubereats.com/store/slugchurn', null, '2026-06-17 10:00:00');

    $categories = [['name' => 'Tacos', 'items' => [scrapedDish('Fish Tacos'), scrapedDish('Beef Tacos')]]];
    mockMenuScrape($categories);
    runMenuFetch($user);

    $before = DB::connection('pgsql')->table('content.item_slugs')->orderBy('slug')->get(['id', 'item_id', 'slug'])->toArray();
    expect($before)->toHaveCount(2);

    mockMenuScrape($categories);
    runMenuFetch($user);

    // Byte-identical ROW IDS — asserting only the slug would still pass under a
    // delete-and-remint, which would reset redirect history and can permute the
    // -N suffixes between two same-based dishes.
    $after = DB::connection('pgsql')->table('content.item_slugs')->orderBy('slug')->get(['id', 'item_id', 'slug'])->toArray();
    expect($after)->toEqual($before);
});

it('forgets a dropped dish before minting the replacement that shares its slug base', function () {
    $user = menuUser('slugorder');
    ordering($user, 'https://www.ubereats.com/store/slugorder', null, '2026-06-17 10:00:00');

    // "Café Latte" and "Cafe Latte" are DIFFERENT dishes to normalizeName()
    // (the accented byte is stripped, not transliterated → "caf latte" vs
    // "cafe latte"), so they hash to different coords and are two items — but
    // Str::slug transliterates both to the same base `cafe-latte`.
    mockMenuScrape([['name' => 'Drinks', 'items' => [scrapedDish('Café Latte')]]]);
    runMenuFetch($user);

    $oldId = menuContentId($user, 'Café Latte');
    expect(menuContentSlugRow($user, $oldId)?->slug)->toBe('cafe-latte');

    mockMenuScrape([['name' => 'Drinks', 'items' => [scrapedDish('Cafe Latte')]]]);
    runMenuFetch($user);

    $newId = menuContentId($user, 'Cafe Latte');
    expect($newId)->not->toBe($oldId);
    // This is why persist() retires BEFORE it writes: mint-first would hand
    // this `cafe-latte-2`, and ensureCurrent() short-circuits on an unchanged
    // base afterwards, so it would stay there permanently.
    expect(menuContentSlugRow($user, $newId)?->slug)->toBe('cafe-latte');
    expect(menuContentSlugCount($user, $oldId))->toBe(0);
});

it('frees scraped dish slugs when the last ordering link is removed', function () {
    $user = menuUser('slugclear');
    ordering($user, 'https://www.ubereats.com/store/slugclear', null, '2026-06-17 10:00:00');

    mockMenuScrape([['name' => 'Mains', 'items' => [scrapedDish('Scraped Dish')]]]);
    runMenuFetch($user);
    $scrapedId = menuContentId($user, 'Scraped Dish');
    expect(menuContentSlugRow($user, $scrapedId)?->slug)->toBe('scraped-dish');

    // No ordering platform left at all -> clearScrapedContent().
    IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->delete();
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    expect(menuContentSlugCount($user, $scrapedId))->toBe(0);
});

// The legacy half of these two tests — a hand-added site.menu_items dish whose
// site.item_slugs row had to survive a scrape — retired in slice 7 Phase 6 with
// both tables. An owner-authored dish is a content.* item now and its slug
// lives in content.item_slugs, covered by the content-lane cases above.

// ── the collision surface Unit 5 opened, restated for the content lane ──
// A scan dish whose name is a DIFFERENT dish by the coord's normaliser but
// whose Str::slug() collides with an existing dish's slug hits the allocator's
// retry path. Slice 7 Task 8 moved both halves: the slugs are
// content.item_slugs (ContentItemSlugAllocator, minted by
// ProjectionWriter::refreshItemCaches) and the apply no longer opens a
// transaction of its own — writeManualItem() manages its own boundaries and
// ProjectionWriter's docblock forbids nesting it. What still has to hold is
// that the collision resolves and every LATER item in the batch still lands.

it('commits a scan apply whose new dish collides with an existing slug base', function () {
    $user = menuUser('scanslugclash');

    // "Café Latte" holds the `cafe-latte` slug (Str::slug transliterates the
    // accent away), but the coord normalises accents to a SPACE — "caf latte"
    // ≠ "cafe latte" — so the scan item below is a NEW dish that wants an
    // already-taken base.
    seedContentMenu($user, ['content_source' => 'uber-eats'], [
        ['name' => 'Drinks', 'items' => [['name' => 'Café Latte', 'base_price' => 5.0]]],
    ]);
    $existing = menuContentRows($user)['Café Latte'];
    $existingRow = menuContentSlugRow($user, (string) $existing->id);
    expect($existingRow?->slug)->toBe('cafe-latte');

    $res = actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [
            ['name' => 'Cafe Latte', 'description' => 'House blend.', 'price' => 5.5, 'category' => 'Specials'],
            // A SECOND item processed after the colliding one — its landing is
            // what proves the collision did not take the rest of the batch
            // down with it.
            ['name' => 'Blueberry Muffin', 'description' => null, 'price' => 4.0, 'category' => 'Specials'],
        ],
    ])->assertOk();

    expect($res->json())->toBe(['updated' => 0, 'added' => 2, 'skipped' => 0]);

    $rows = menuContentRows($user);
    $minted = $rows['Cafe Latte'];
    expect($minted->description)->toBe('House blend.');
    expect(menuContentSlugRow($user, (string) $minted->id)?->slug)->toBe('cafe-latte-2');

    // The membership write runs after the colliding mint — its landing is the
    // second proof.
    $scanCategory = menuContentCategories($user)['Specials'];
    expect((string) $scanCategory->external_ref)->toBe(MenuScanApplier::categoryRefFor('scan', 'Specials'));
    expect($minted->category_ids)->toBe([(string) $scanCategory->id]);

    expect(menuContentSlugRow($user, (string) $rows['Blueberry Muffin']->id)?->slug)->toBe('blueberry-muffin');

    // The incumbent keeps its row and its bare base — the newcomer took -2.
    $existingAfter = menuContentSlugRow($user, (string) $existing->id);
    expect($existingAfter->id)->toBe($existingRow->id);
    expect($existingAfter->slug)->toBe('cafe-latte');
});

it('scan apply persists the batch to scan_items for post-rebuild re-apply', function () {
    $user = menuUser('scanpersist1');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Margherita Pizza', 'description' => 'Classic.', 'price' => 14.5, 'category' => 'Pizzas']],
    ])->assertOk();

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->scan_items['source'])->toBe('upload');
    expect($menu->scan_items['scannedAt'])->not->toBeNull();
    expect($menu->scan_items['items'])->toHaveCount(1);
    expect($menu->scan_items['items'][0]['name'])->toBe('Margherita Pizza');
});

it('scan apply merges scan_items with an existing blob, new batch winning by name', function () {
    $user = menuUser('scanpersist2');
    Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD', 'fetch_status' => 'ok',
        'scan_items' => [
            'items' => [
                ['name' => 'Garlic Bread', 'description' => 'From Google.', 'price' => 8.0, 'category' => 'Sides', 'dietary' => null],
                ['name' => 'Tiramisu', 'description' => null, 'price' => 12.0, 'category' => 'Desserts', 'dietary' => null],
            ],
            'source' => 'google-photos',
            'scannedAt' => now()->subDay()->toIso8601String(),
        ],
    ]);

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'garlic bread', 'description' => 'From my upload.', 'price' => 9.0, 'category' => 'Starters']],
    ])->assertOk();

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $items = collect($menu->scan_items['items']);

    // Google's Tiramisu kept; Google's Garlic Bread replaced by the upload's
    // (name match is case-insensitive + trimmed).
    expect($items->pluck('name')->all())->toContain('Tiramisu', 'garlic bread');
    expect($items->firstWhere('name', 'garlic bread')['description'])->toBe('From my upload.');
    expect($items->pluck('name')->all())->not->toContain('Garlic Bread');
    expect($menu->scan_items['source'])->toBe('upload');
});
