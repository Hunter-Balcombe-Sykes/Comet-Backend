<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuApifyScraper;
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

// ── Source resolution (Uber Eats > DoorDash > none) ───────────────────

it('resolves uber eats over doordash for menu content', function () {
    $user = menuUser('m1');
    // DoorDash is newer, but Uber Eats wins on content priority regardless.
    ordering($user, 'https://www.doordash.com/store/ollies-12345/', null, '2026-06-17 10:00:00');
    ordering($user, 'https://www.ubereats.com/au/store/ollies/abc?diningMode=DELIVERY', null, '2026-06-17 09:00:00');

    $resolved = app(MenuSource::class)->resolve($user);
    expect($resolved['platform'])->toBe('uber-eats');
    // store_url normalized — query stripped so pickup/delivery variants collapse.
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

// ── Read-time order-link computation ──────────────────────────────────

it('routes pickup and delivery to the most-recent typed entry', function () {
    $user = menuUser('m4');
    ordering($user, 'https://www.ubereats.com/store/old-pickup', 'pickup', '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/store/new-pickup', 'pickup', '2026-06-17 10:00:00');
    ordering($user, 'https://www.ubereats.com/store/delivery', 'delivery', '2026-06-17 09:30:00');

    $links = app(MenuSource::class)->links($user);
    expect($links['pickupUrl'])->toBe('https://www.ubereats.com/store/new-pickup');  // newest pickup
    expect($links['deliveryUrl'])->toBe('https://www.ubereats.com/store/delivery');
    expect($links['orderUrl'])->toBeNull();  // typed links exist → no single Order button
});

it('falls back to a single order button when no typed links exist', function () {
    $user = menuUser('m5');
    ordering($user, 'https://www.ubereats.com/store/old', null, '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/store/new', null, '2026-06-17 10:00:00');

    $links = app(MenuSource::class)->links($user);
    expect($links['pickupUrl'])->toBeNull();
    expect($links['deliveryUrl'])->toBeNull();
    expect($links['orderUrl'])->toBe('https://www.ubereats.com/store/new');  // most-recent overall
});

// ── MenuFetchJob ──────────────────────────────────────────────────────

it('scrapes and stores the menu on source change', function () {
    $user = menuUser('m6');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetch')->once()->andReturn([
            'rating' => null, 'reviewCount' => null, 'currency' => 'AUD',
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'price' => 12.5]]]],
        ]);
    });

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->source)->toBe('uber-eats');
    expect($menu->fetch_status)->toBe('ok');
    expect($menu->store_url)->toBe('https://www.ubereats.com/store/x');
    expect($menu->categories[0]['name'])->toBe('Pizzas');
});

it('skips the paid scrape when the store url is unchanged', function () {
    $user = menuUser('m7');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    Menu::create([
        'user_id' => $user->id, 'source' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x',
        'categories' => [['name' => 'A', 'items' => []]], 'fetch_status' => 'ok',
    ]);

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetch')->never());

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class));
    expect(Menu::query()->where('user_id', $user->id)->firstOrFail()->categories[0]['name'])->toBe('A');
});

it('forces a re-scrape even when the store url is unchanged', function () {
    $user = menuUser('m8');
    ordering($user, 'https://www.ubereats.com/store/x', null, '2026-06-17 10:00:00');
    Menu::create([
        'user_id' => $user->id, 'source' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x',
        'categories' => [['name' => 'Old', 'items' => []]], 'fetch_status' => 'ok',
    ]);

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetch')->once()->andReturn([
            'rating' => 4.5, 'reviewCount' => 100, 'currency' => 'AUD',
            'categories' => [['name' => 'Fresh', 'items' => [['name' => 'New', 'price' => 9.0]]]],
        ]);
    });

    (new MenuFetchJob((string) $user->id, true))->handle(app(MenuSource::class), app(MenuApifyScraper::class));
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->categories[0]['name'])->toBe('Fresh');
    expect($menu->rating)->toBe(4.5);
});

it('clears the menu when no ordering source remains', function () {
    $user = menuUser('m9');
    Menu::create([
        'user_id' => $user->id, 'source' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x',
        'categories' => [['name' => 'A', 'items' => []]], 'fetch_status' => 'ok',
    ]);

    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetch')->never());

    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class));
    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();  // soft-deleted
});

// ── MenuController ────────────────────────────────────────────────────

it('reports menu status with item count and source', function () {
    $user = menuUser('m10');
    Menu::create([
        'user_id' => $user->id, 'source' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x',
        'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'A'], ['name' => 'B']]]],
        'fetch_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/menu/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('itemCount', 2)
        ->assertJsonPath('source', 'uber-eats')
        ->assertJsonPath('fetchStatus', 'ok');
});

it('returns the full menu with computed order links', function () {
    $user = menuUser('m11');
    ordering($user, 'https://www.ubereats.com/store/p', 'pickup', '2026-06-17 09:00:00');
    ordering($user, 'https://www.ubereats.com/store/d', 'delivery', '2026-06-17 10:00:00');
    Menu::create([
        'user_id' => $user->id, 'source' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/p',
        'rating' => 4.7, 'currency' => 'AUD',
        'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'price' => 12.5]]]],
        'fetch_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/menu')
        ->assertOk()
        ->assertJsonPath('source', 'uber-eats')
        ->assertJsonPath('rating', 4.7)
        ->assertJsonPath('categories.0.items.0.name', 'Margherita')
        ->assertJsonPath('links.pickupUrl', 'https://www.ubereats.com/store/p')
        ->assertJsonPath('links.deliveryUrl', 'https://www.ubereats.com/store/d')
        ->assertJsonPath('links.orderUrl', null);
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
