<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\InstagramAutoSync;
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

it('auto-connects through InstagramAutoSync only when the caller marks the origin', function (bool $marked, bool $expectDispatch) use ($freshaUrl) {
    // The whole flow distinction in one assertion: a staff/ManyChat build passes
    // true, every other Instagram origin takes the false default and leaves the
    // menu choice to the picker.
    $user = User::factory()->create(['account_type' => 'partna']);

    app(InstagramAutoSync::class)->seed((string) $user->id, [$freshaUrl], $marked);

    $expectDispatch
        ? Queue::assertPushed(ConnectFetchJob::class)
        : Queue::assertNotPushed(ConnectFetchJob::class);
})->with([
    'staff build (marked)' => [true, true],
    'public signup / dashboard / refresh (unmarked)' => [false, false],
]);

it('still seeds the fresha link itself when auto-connect is off', function () use ($freshaUrl) {
    // Not auto-connecting must not mean "drop the link" — the row is still
    // written with selection:null so the frontend picker has a URL to work from,
    // and it is canonical so GET /platforms/fresha/team can resolve the slug.
    $user = User::factory()->create(['account_type' => 'partna']);

    app(InstagramAutoSync::class)->seed((string) $user->id, [$freshaUrl], false);

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;

    expect($payload['url'])->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna')
        ->and($payload['selection'])->toBeNull()
        ->and($payload)->not->toHaveKey('connectMode');
});

it('claims the daily cap through DailyCounterClaim, not a private counter', function () {
    // GS-1 forbids raw Cache::add/remember outside the cache services, and the
    // hand-rolled `add() then increment()` form this replaced is the exact
    // defect DailyCounterClaim was extracted to close: if the key expires
    // between the two round trips, INCRBY recreates it with NO TTL, which under
    // instance-wide volatile-lru is permanent inevictable ballast.
    //
    // Asserted against AutoBookingConnectDispatcher, which is where the counter
    // lives since 2026-08-19. It moved out of the trait when a THIRD producer
    // (SourceReconciler, for unclaimed pre-account sites) needed the dispatch and
    // could not take the trait; the trait's two methods now delegate there. The
    // invariant this test defends is unchanged and in fact stronger — ONE
    // install-wide ceiling, now shared by three producers rather than two.
    $dispatcher = file_get_contents(base_path('app/Services/Platforms/AutoBookingConnectDispatcher.php'));

    $this->assertStringContainsString(
        'DailyCounterClaim::claim(CacheKeyGenerator::freshaAutoConnectDaily(',
        $dispatcher,
        'The daily cap must claim through DailyCounterClaim with a CacheKeyGenerator key.'
    );

    // ...and exactly once in the codebase: a second claim site is a second
    // ceiling, which is the per-route budget this whole design rejects.
    foreach ([
        'app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php',
        'app/Services/Platforms/LinkRouter.php',
        'app/Services/Platforms/GoogleBusinessAutoSync.php',
        'app/Routing/SourceReconciler.php',
    ] as $file) {
        $this->assertStringNotContainsString(
            'DailyCounterClaim::claim(',
            file_get_contents(base_path($file)),
            "{$file}: the daily ceiling must be claimed in AutoBookingConnectDispatcher alone, or it stops being install-wide."
        );
    }

    foreach ([
        'app/Services/Platforms/AutoBookingConnectDispatcher.php',
        'app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php',
        'app/Services/Platforms/LinkRouter.php',
        'app/Services/Platforms/GoogleBusinessAutoSync.php',
    ] as $file) {
        $this->assertStringNotContainsString(
            'Cache::add(',
            file_get_contents(base_path($file)),
            "{$file}: a private add()+increment() counter reintroduces the TTL-loss bug DailyCounterClaim exists to prevent."
        );
    }
});
