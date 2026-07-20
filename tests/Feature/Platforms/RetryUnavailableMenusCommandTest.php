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
function retryMenuFor(string $handle): Menu
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => $handle, 'display_name' => $handle,
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => $handle.'@example.com',
    ]);
    $menu = Menu::create(['user_id' => $user->id, 'last_fetched_at' => now()->subHour()]);
    $menu->platformLinks()->create(['platform' => 'ubereats', 'status' => 'unavailable', 'store_url' => 'https://ubereats.com/x']);

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
