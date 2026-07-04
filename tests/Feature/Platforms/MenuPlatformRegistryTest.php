<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuPlatformDriver;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// FOUND-23 registry-extensibility proof: adding a menu-scraping platform is a
// config('partna.menu.platforms') entry + one MenuPlatformDriver class — nothing
// in MenuSource, MenuApifyScraper, MenuMerger, or MenuFetchJob hardcodes a slug.
// This registers a THIRD platform ('menulog') purely via runtime config and
// proves every layer picks it up with zero code change.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Test double for MenuPlatformDriver — returns a canned normalized menu, no HTTP.
class FakeMenuDriver implements MenuPlatformDriver
{
    public function buildInput(string $storeUrl, ?string $address): array
    {
        return ['startUrls' => [['url' => $storeUrl]]];
    }

    public function mapItems(array $items): array
    {
        return [
            'store' => ['name' => 'Menulog Diner', 'currency' => 'AUD'],
            'categories' => [[
                'name' => 'Mains',
                'items' => [[
                    'externalId' => 'ml-1',
                    'name' => 'Menulog Special',
                    'description' => null,
                    'price' => 10.0,
                    'image' => null,
                    'rating' => null,
                    'ratingCount' => null,
                    'badges' => null,
                ]],
            ]],
        ];
    }
}

function registryUser(string $h): User
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

function registryOrdering(User $user, string $url): IntegrationConnection
{
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => $url, 'name' => 'Order'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

/** Registers a fake 'menulog' menu platform at runtime — config only, zero code change. */
function registerMenulogPlatform(): void
{
    config()->set('partna.menu.platforms.menulog', [
        'actor' => 'x~menulog',
        'host_pattern' => '~(^|\.)menulog\.com\.au$~',
        'driver' => FakeMenuDriver::class,
    ]);
}

it('recognizes a menulog url purely by config, with no code change', function () {
    registerMenulogPlatform();
    $user = registryUser('reg1');
    registryOrdering($user, 'https://www.menulog.com.au/restaurants/test-diner');

    $resolved = app(MenuSource::class)->resolve($user);
    expect($resolved['platform'])->toBe('menulog');
    expect($resolved['storeUrl'])->toBe('https://www.menulog.com.au/restaurants/test-diner');

    $plan = app(MenuSource::class)->resolveAll($user);
    expect($plan['contentSource'])->toBe('menulog');
    expect($plan['storeUrls']['menulog'])->toBe('https://www.menulog.com.au/restaurants/test-diner');
});

it('fetchStores scrapes the registered platform through its driver', function () {
    registerMenulogPlatform();
    config(['services.apify.token' => 'test-token']);

    // Keyed by the fake actor id — proves the actor id also comes from config,
    // not a hardcoded ACTORS list.
    Http::fake(['api.apify.com/*' => Http::response([['stub' => true]], 201)]);

    $links = ['menulog' => [
        'pickupUrl' => null,
        'deliveryUrl' => null,
        'storeUrl' => 'https://www.menulog.com.au/restaurants/test-diner',
        'modes' => ['pickup', 'delivery'],
    ]];
    $menus = app(MenuApifyScraper::class)->fetchStores($links, 'u1');

    expect($menus)->toHaveKey('menulog');
    expect($menus['menulog']['categories'][0]['items'][0]['name'])->toBe('Menulog Special');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'x~menulog'));
});

it('merges a menulog-only dish into the union', function () {
    registerMenulogPlatform();

    $menulogMenu = [
        'store' => ['name' => 'Menulog Diner', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [[
            'externalId' => 'ml-1', 'name' => 'Menulog Special', 'description' => null,
            'pickupPrice' => 10.0, 'deliveryPrice' => null, 'image' => null,
            'rating' => null, 'ratingCount' => null, 'badges' => null,
        ]]]],
    ];
    $links = ['menulog' => [
        'pickupUrl' => 'https://menulog/store?diningMode=PICKUP',
        'deliveryUrl' => null,
        'storeUrl' => 'https://menulog/store',
        'modes' => ['pickup'],
    ]];

    $merged = (new MenuMerger)->merge(['menulog' => $menulogMenu], 'menulog', $links);
    $names = collect($merged['categories'])->flatMap(fn ($c) => collect($c['items'])->pluck('name'))->all();
    expect($names)->toContain('Menulog Special');
    expect($merged['categories'][0]['items'][0]['platforms'][0]['platform'])->toBe('menulog');
});

it('MenuFetchJob persists a menu_item_platforms row for the registered platform', function () {
    registerMenulogPlatform();
    $user = registryUser('reg2');
    registryOrdering($user, 'https://www.menulog.com.au/restaurants/test-diner');

    $menulogMenu = [
        'store' => ['name' => 'Menulog Diner', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [[
            'externalId' => 'ml-1', 'name' => 'Menulog Special', 'description' => null,
            'pickupPrice' => 10.0, 'deliveryPrice' => 10.0, 'image' => null,
            'rating' => null, 'ratingCount' => null, 'badges' => null,
        ]]]],
    ];
    test()->mock(MenuApifyScraper::class, function ($m) use ($menulogMenu) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['menulog' => $menulogMenu]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));

    // menu_item_platforms.platform is intentionally un-CHECKed (by design, see
    // 20260701140100_menu_item_platforms_table.sql) so this row is safe on
    // Postgres regardless. site.menus.content_source ALSO now writes 'menulog'
    // cleanly here — but only because 20260704170000_drop_menu_platform_checks
    // dropped the old hardcoded ('uber-eats','doordash') CHECK. SQLite (the test
    // driver) never enforced that CHECK either way, so a green run here does NOT
    // by itself prove the Postgres write is safe — the migration is what makes it so.
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->content_source)->toBe('menulog');

    $itemPlatform = MenuItemPlatform::query()->where('platform', 'menulog')->first();
    expect($itemPlatform)->not->toBeNull();
    expect((float) $itemPlatform->pickup_price)->toBe(10.0);
});
