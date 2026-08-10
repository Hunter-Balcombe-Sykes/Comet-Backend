<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
    Queue::fake();
});

$freshaUrl = 'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260';

it('dispatches an auto connect for an instagram-origin fresha link', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertPushed(ConnectFetchJob::class, fn (ConnectFetchJob $job): bool => $job->platform === 'fresha' && $job->systemInitiated === true);
});

it('stamps connectMode auto and a canonical url on the row', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;

    expect($payload['connectMode'])->toBe('auto')
        ->and($payload['url'])->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('does NOT dispatch for a dashboard paste', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    // The shape CustomLinksController uses: origin flag left at its default.
    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(maxProbes: 0));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT dispatch when the kill switch is off', function () use ($freshaUrl) {
    config()->set('partna.connect.auto_booking.enabled', false);
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT dispatch when the booking gate denies the link', function () use ($freshaUrl) {
    // business + food sector: gateAllows() returns false for 'booking'.
    $user = User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT dispatch once the global daily cap is spent', function () use ($freshaUrl) {
    config()->set('partna.connect.auto_booking.global_daily_cap', 1);
    Cache::put(CacheKeyGenerator::freshaAutoConnectDaily(now()->format('Y-m-d')), 1, now()->addDay());

    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('counts each dispatch against the daily cap', function () use ($freshaUrl) {
    $user = User::factory()->create(['account_type' => 'partna']);

    app(LinkRouter::class)->route($user, $freshaUrl, new RouteContext(autoConnectBooking: true));

    expect((int) Cache::get(CacheKeyGenerator::freshaAutoConnectDaily(now()->format('Y-m-d'))))->toBe(1);
});

it('claims the daily cap through DailyCounterClaim, not a private counter', function () {
    // GS-1 forbids raw Cache::add/remember outside the cache services, and the
    // hand-rolled `add() then increment()` form this replaced is the exact
    // defect DailyCounterClaim was extracted to close: if the key expires
    // between the two round trips, INCRBY recreates it with NO TTL, which under
    // instance-wide volatile-lru is permanent inevictable ballast.
    $source = file_get_contents(base_path('app/Services/Platforms/LinkRouter.php'));

    $this->assertStringContainsString(
        'DailyCounterClaim::claim(CacheKeyGenerator::freshaAutoConnectDaily(',
        $source,
        'The daily cap must claim through DailyCounterClaim with a CacheKeyGenerator key.'
    );
    $this->assertStringNotContainsString(
        'Cache::add(',
        $source,
        'A private add()+increment() counter reintroduces the TTL-loss bug DailyCounterClaim exists to prevent.'
    );
});
