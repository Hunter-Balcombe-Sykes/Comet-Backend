<?php

/** @phpstan-ignore-all */

// OV-H wiring: a terminal menu-scrape failure publishes the non-critical content_scrape
// warning via MenuFetchJob::failed().
//
// LIFE-12 (the episode-boundary tests below) is the reason site.menus carries
// last_successful_fetch_at at all. The dedupe key must survive an ongoing outage
// but reopen after a genuine recovery, and last_fetched_at cannot express that:
// three call sites stamp it (MenuFetchJob's success AND soft-unavailable
// branches, MenuContentController::resolveMenu on every manual dish edit,
// MenuScanApplier::resolveMenu on every photo-scan apply) and only one of them
// means a scrape worked. These tests drive the real write paths, not hand-stamped
// columns, so they pin that distinction rather than restating it.

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();          // site.menus (looked up in failed())
    setupContactInboxSchema();  // notifications.notifications
    // MenuFetchJob + MenuItemObserver write site.item_slugs best-effort — without
    // the table their try/catch swallows a "no such table" and masks slug bugs.
    setupItemSlugsTable();
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

/** Menu is a food-business capability (can_use_menu → isBusiness() && isFood()). */
function msfnUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'business',
        'sector' => 'restaurant',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    // A site is needed for cache busting on menu writes (real users always have one).
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

/** An Uber Eats online-ordering link, so MenuSource::resolveAll() gives the job something to scrape. */
function msfnOrdering(User $user): IntegrationConnection
{
    $rid = 'order-'.substr(sha1((string) $user->id), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => 'https://www.ubereats.com/store/x', 'name' => 'Order', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

function msfnRunFetch(User $user): void
{
    (new MenuFetchJob((string) $user->id))->handle(
        app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class)
    );
}

function msfnScrapeWarnings(User $user): int
{
    return DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'content_scrape')
        ->count();
}

it('publishes a non-critical content_scrape warning when the menu job fails terminally', function () {
    Exceptions::fake();

    (new MenuFetchJob('pro-1'))->failed(new RuntimeException('scrape blew up'));

    $row = DB::table('notifications.notifications')
        ->where('user_id', 'pro-1')
        ->where('category', 'content_scrape')
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->critical)->toBe(0);          // in-app only — the menu self-heals
    expect($row->ends_at)->not->toBeNull();
});

it('LIFE-12: a manual menu edit between two terminal failures does NOT reopen the episode', function () {
    Exceptions::fake();
    $user = msfnUser('msfn1');

    // Failure 1 — no scrape has ever succeeded, so the episode key is 'never'.
    (new MenuFetchJob((string) $user->id))->failed(new RuntimeException('scrape blew up'));
    expect(msfnScrapeWarnings($user))->toBe(1);

    // The owner curates their menu while the Uber Eats link is broken. This is a
    // REAL write path (MenuContentController::resolveMenu, one of last_fetched_at's
    // three writers) and it advances last_fetched_at — the exact reason that column
    // cannot be the episode boundary.
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->last_fetched_at)->not->toBeNull();         // writer #2 fired…
    expect($menu->last_successful_fetch_at)->toBeNull();     // …but nothing was fixed

    // Failure 2 — same ongoing outage. Keyed on last_fetched_at this would have
    // published a second warning; keyed on last_successful_fetch_at it dedupes.
    (new MenuFetchJob((string) $user->id))->failed(new RuntimeException('still broken'));

    expect(msfnScrapeWarnings($user))->toBe(1);
});

it('LIFE-12: a genuine successful scrape between two failures DOES reopen the episode', function () {
    Exceptions::fake();
    $user = msfnUser('msfn2');
    msfnOrdering($user);

    (new MenuFetchJob((string) $user->id))->failed(new RuntimeException('boom'));
    expect(msfnScrapeWarnings($user))->toBe(1);

    // Real recovery: a successful MenuFetchJob run — the ONLY writer of
    // last_successful_fetch_at.
    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Pizzas', 'items' => [['name' => 'Margherita', 'pickupPrice' => 12.5, 'deliveryPrice' => 12.5]]]],
        ]]);
    });
    msfnRunFetch($user);

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->fetch_status)->toBe('ok');
    expect($menu->last_successful_fetch_at)->not->toBeNull();

    // A NEW outage after a working menu is news — the user must be told again.
    (new MenuFetchJob((string) $user->id))->failed(new RuntimeException('broke again'));

    expect(msfnScrapeWarnings($user))->toBe(2);
});

it('LIFE-12: the soft-unavailable branch is not a recovery and does NOT reopen the episode', function () {
    Exceptions::fake();
    $user = msfnUser('msfn3');
    msfnOrdering($user);

    (new MenuFetchJob((string) $user->id))->failed(new RuntimeException('boom'));
    expect(msfnScrapeWarnings($user))->toBe(1);

    // Nothing usable from ANY connected platform: handle() stamps last_fetched_at
    // and marks the menu unavailable (writer #1b). That is a failure wearing a
    // completed-run costume, so it must not move the boundary.
    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => null]);
    });
    msfnRunFetch($user);

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->fetch_status)->toBe('unavailable');
    expect($menu->last_fetched_at)->not->toBeNull();
    expect($menu->last_successful_fetch_at)->toBeNull();

    (new MenuFetchJob((string) $user->id))->failed(new RuntimeException('still broken'));

    expect(msfnScrapeWarnings($user))->toBe(1);
});
