<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Queue::fake();
});

// Creates a User + Menu with one 'unavailable' platform link — the minimum
// fixture the retry command selects on. The real MenuPlatformLink column is
// `store_url`, not `url`.
function retryMenuFor(string $handle, string $status = 'unavailable'): Menu
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => $handle, 'display_name' => $handle,
        'first_name' => $handle,
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => $handle.'@example.com',
    ]);
    $menu = Menu::create(['user_id' => $user->id, 'last_fetched_at' => now()->subHour()]);
    $menu->platformLinks()->create(['platform' => 'ubereats', 'status' => $status, 'store_url' => 'https://ubereats.com/x']);

    return $menu;
}

it('caps dispatch at the --limit', function () {
    foreach (range(1, 4) as $i) {
        retryMenuFor("u{$i}");
    }

    $this->artisan('menu:retry-unavailable', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(MenuFetchJob::class, 2);
});

it('stops dispatching once the menu apify budget is exhausted', function () {
    config()->set('partna.limits.apify.actors.menu', 0); // no budget at all
    retryMenuFor('u1');

    $this->artisan('menu:retry-unavailable')->assertSuccessful();

    Queue::assertNothingPushed();
});

// The window bound was inverted against its own stated intent. It read
// last_fetched_at, and MenuFetchJob advances last_fetched_at on every FAILED
// attempt (MenuFetchJob.php's 'unavailable' branch) — so each retry pushed the
// row back inside its own window and "eventually crosses the window and stops"
// never happened for the one case it existed to stop. guzman-y-gomez's brand
// URL was re-forced every 15 minutes for as long as it stayed connected.
// The bound now reads last_successful_fetch_at, whose single writer is the
// fetch_status='ok' branch.

it('stops retrying a menu that has not succeeded inside the window', function () {
    $menu = retryMenuFor('dead');
    // The permanently-dead shape: attempted a minute ago (as it has been all
    // day), last actually worked well outside the 6h window.
    $menu->forceFill([
        'last_fetched_at' => now()->subMinute(),
        'last_successful_fetch_at' => now()->subDays(2),
    ])->save();

    $this->artisan('menu:retry-unavailable')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('still retries a working store that just went flaky', function () {
    $menu = retryMenuFor('flaky');
    $menu->forceFill([
        'last_fetched_at' => now()->subMinute(),
        'last_successful_fetch_at' => now()->subHours(2),
    ])->save();

    $this->artisan('menu:retry-unavailable')->assertSuccessful();

    Queue::assertPushed(MenuFetchJob::class, 1);
});

it('still retries a fresh connection whose first scrape was blocked', function () {
    // Never succeeded, so there is no last_successful_fetch_at to bound on —
    // falls back to the menu's own age, which is what keeps a genuine
    // first-scrape bot-block recoverable instead of dead on arrival.
    $menu = retryMenuFor('newborn');
    $menu->forceFill(['last_fetched_at' => now()->subMinute(), 'last_successful_fetch_at' => null])->save();

    $this->artisan('menu:retry-unavailable')->assertSuccessful();

    Queue::assertPushed(MenuFetchJob::class, 1);
});

it('stops retrying an old connection that never once succeeded', function () {
    $menu = retryMenuFor('stillborn');
    $menu->forceFill(['last_fetched_at' => now()->subMinute(), 'last_successful_fetch_at' => null])->save();
    // created_at is not fillable on the way in; age it directly.
    Menu::query()->whereKey($menu->id)->update(['created_at' => now()->subDays(2)]);

    $this->artisan('menu:retry-unavailable')->assertSuccessful();

    Queue::assertNothingPushed();
});

// The 2026-09-03 status split. This cron is the reason the split had to reach
// the selection query: the three values are not interchangeable, and reading
// them as "anything that isn't ok" would re-bill a paid actor run forever to be
// told the same thing.
it('retries a blocked scrape — the transient bot-block this cron exists for', function () {
    retryMenuFor('retry-blocked', 'blocked');

    $this->artisan('menu:retry-unavailable')->assertExitCode(0);

    Queue::assertPushed(MenuFetchJob::class, 1);
});

it('retries an empty_menu scrape — the store is there and mapped to nothing', function () {
    retryMenuFor('retry-empty', 'empty_menu');

    $this->artisan('menu:retry-unavailable')->assertExitCode(0);

    Queue::assertPushed(MenuFetchJob::class, 1);
});

it('never retries not_found — the actor already answered, and answering again costs money', function () {
    retryMenuFor('retry-notfound', 'not_found');

    $this->artisan('menu:retry-unavailable')->assertExitCode(0);

    Queue::assertNotPushed(MenuFetchJob::class);
});

it('still retries the legacy blanket status, which the http driver lane still writes', function () {
    retryMenuFor('retry-legacy', 'unavailable');

    $this->artisan('menu:retry-unavailable')->assertExitCode(0);

    Queue::assertPushed(MenuFetchJob::class, 1);
});
