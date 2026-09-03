<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuPlatformLink;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// site.menu_platform_links.status used to collapse three genuinely different
// scrape failures into one 'unavailable' value. MenuApifyScraper already
// tells them apart internally (mapResponse()/attemptScrape()) — this file
// pins that MenuFetchJob now carries the real reason all the way out to the
// written status, end-to-end through the real scraper + a faked HTTP layer
// (never MenuApifyScraper mocked away), mirroring MenuApifyScraperTest.php's
// fake shapes for the real Apify actor endpoint.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    config(['services.apify.token' => 'apify-test-token']);
    // Chained follow-up dispatches (RetryMenuFetchJob, the deferred photo
    // scan) are not what this file is testing — fake the whole queue so
    // handle()'s settled() hook never runs a real second scrape under the
    // sync queue connection.
    Queue::fake();
});

/** Menu is a food-business-only capability — mirrors MenuTest.php's menuUser(). */
function statusSplitUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'business',
        'sector' => 'restaurant',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

/** A single untyped Uber Eats ordering link — the simplest fetchStores() target (one platform, one target key). */
function statusSplitConnectUberEats(User $user, string $storeUrl): IntegrationConnection
{
    $rid = 'order-'.substr(sha1(strtolower($storeUrl)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => [
            'id' => $rid,
            'provider' => 'custom',
            'url' => $storeUrl,
            'name' => 'Order',
            'source' => 'manual',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

function statusSplitRunFetch(User $user): void
{
    (new MenuFetchJob((string) $user->id))->handle(app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class));
}

function statusSplitStatus(User $user): ?string
{
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();

    return MenuPlatformLink::query()->where('menu_id', $menu->id)->where('platform', 'uber-eats')->value('status');
}

it('writes blocked when the Apify actor run itself is not successful', function () {
    $user = statusSplitUser('split1');
    statusSplitConnectUberEats($user, 'https://www.ubereats.com/store/blocked');

    Http::fake(['api.apify.com/*' => Http::response('Actor not rented', 403)]);

    statusSplitRunFetch($user);

    expect(statusSplitStatus($user))->toBe('blocked');
    expect(Menu::query()->where('user_id', $user->id)->value('fetch_status'))->toBe('unavailable');
});

it('writes not_found when the actor runs successfully but the dataset is empty', function () {
    $user = statusSplitUser('split2');
    statusSplitConnectUberEats($user, 'https://www.ubereats.com/store/notfound');

    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    statusSplitRunFetch($user);

    expect(statusSplitStatus($user))->toBe('not_found');
    expect(Menu::query()->where('user_id', $user->id)->value('fetch_status'))->toBe('unavailable');
});

it('writes empty_menu when the store maps fine but has zero categories', function () {
    $user = statusSplitUser('split3');
    statusSplitConnectUberEats($user, 'https://www.ubereats.com/store/emptymenu');

    // Non-empty dataset item (so it clears the not_found check) whose
    // menuItems list is empty — UberEatsMenuDriver::mapItems() groups
    // menuItems into categories, so an empty list maps to categories: [].
    Http::fake(['api.apify.com/*' => Http::response([[
        'title' => 'Empty Store',
        'currencyCode' => 'AUD',
        'menuItems' => [],
    ]], 201)]);

    statusSplitRunFetch($user);

    expect(statusSplitStatus($user))->toBe('empty_menu');
    expect(Menu::query()->where('user_id', $user->id)->value('fetch_status'))->toBe('unavailable');
});

it('still writes ok for a successful fetch', function () {
    $user = statusSplitUser('split4');
    statusSplitConnectUberEats($user, 'https://www.ubereats.com/store/ok');

    Http::fake(['api.apify.com/*' => Http::response([[
        'title' => 'Ollies',
        'currencyCode' => 'AUD',
        'menuItems' => [['name' => 'Margherita', 'section' => 'Pizzas', 'price' => 12.5]],
    ]], 201)]);

    statusSplitRunFetch($user);

    expect(statusSplitStatus($user))->toBe('ok');
    expect(Menu::query()->where('user_id', $user->id)->value('fetch_status'))->toBe('ok');
});
