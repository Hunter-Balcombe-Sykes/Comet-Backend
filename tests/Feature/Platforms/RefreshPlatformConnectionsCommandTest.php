<?php

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Queue::fake();
});

function dispatchUser(): User
{
    return User::create([
        'handle' => 'cron', 'handle_lc' => 'cron', 'display_name' => 'Cron',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'cron@example.com',
    ]);
}

function conn(User $user, string $platform, array $attrs): IntegrationConnection
{
    return IntegrationConnection::create(array_merge([
        'user_id' => $user->id, 'platform' => $platform, 'resource_id' => $platform,
        'payload' => ['handle' => 'c'],
    ], $attrs));
}

it('dispatches a job for a stale refreshable connection', function () {
    $user = dispatchUser();
    $c = conn($user, 'youtube', ['last_refreshed_at' => now()->subWeek()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class,
        fn ($j) => $j->connectionId === $c->id && $j->platform === 'youtube');
});

it('dispatches a never-refreshed connection', function () {
    $user = dispatchUser();
    $c = conn($user, 'youtube', ['last_refreshed_at' => null]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $c->id);
});

it('does not dispatch a fresh connection (within TTL)', function () {
    $user = dispatchUser();
    conn($user, 'youtube', ['last_refreshed_at' => now()->subHour()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('does not dispatch non-refreshable platforms (instagram)', function () {
    $user = dispatchUser();
    conn($user, 'instagram', ['last_refreshed_at' => now()->subYear(), 'payload' => ['username' => 'ig']]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('does not dispatch a connection at the failure cap', function () {
    $user = dispatchUser();
    conn($user, 'youtube', ['last_refreshed_at' => now()->subWeek(), 'consecutive_failures' => 10]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('does not dispatch inactive connections', function () {
    $user = dispatchUser();
    conn($user, 'youtube', ['last_refreshed_at' => now()->subWeek(), 'is_active' => false]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

// RV-5: cap + stagger the fan-out.

it('caps the number of jobs dispatched per platform per run', function () {
    // The one test that catches lazyById() silently overwriting limit()/orderBy():
    // with lazyById() left in, all 6 are pushed regardless of this config.
    config()->set('partna.refresh.dispatch.max_per_platform', 3);
    $user = dispatchUser();
    for ($i = 0; $i < 6; $i++) {
        conn($user, 'youtube', ['resource_id' => "yt-{$i}", 'last_refreshed_at' => now()->subWeek()]);
    }

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class, 3);
});

it('dispatches the oldest-refreshed connections first when the due set exceeds the cap', function () {
    config()->set('partna.refresh.dispatch.max_per_platform', 2);
    $user = dispatchUser();
    $c4d = conn($user, 'youtube', ['resource_id' => 'yt-4d', 'last_refreshed_at' => now()->subDays(4)]);
    $c3d = conn($user, 'youtube', ['resource_id' => 'yt-3d', 'last_refreshed_at' => now()->subDays(3)]);
    conn($user, 'youtube', ['resource_id' => 'yt-2d', 'last_refreshed_at' => now()->subDays(2)]);
    $c1d = conn($user, 'youtube', ['resource_id' => 'yt-1d', 'last_refreshed_at' => now()->subDay()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class, 2);
    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $c4d->id);
    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $c3d->id);
    Queue::assertNotPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $c1d->id);
});

it('sorts a never-refreshed connection ahead of a stale one (NULLS FIRST)', function () {
    // On SQLite plain ASC already sorts NULLs first, so this assertion only bites
    // on Postgres — it exists to guard the explicit NULLS FIRST clause, not to
    // catch a SQLite regression.
    config()->set('partna.refresh.dispatch.max_per_platform', 1);
    $user = dispatchUser();
    $never = conn($user, 'youtube', ['resource_id' => 'yt-never', 'last_refreshed_at' => null]);
    conn($user, 'youtube', ['resource_id' => 'yt-old', 'last_refreshed_at' => now()->subYear()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class, 1);
    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $never->id);
});

it('applies the cap independently per platform, not globally', function () {
    config()->set('partna.refresh.dispatch.max_per_platform', 1);
    $user = dispatchUser();
    conn($user, 'youtube', ['resource_id' => 'yt-a', 'last_refreshed_at' => now()->subWeek()]);
    conn($user, 'youtube', ['resource_id' => 'yt-b', 'last_refreshed_at' => now()->subWeek()]);
    conn($user, 'vimeo', ['resource_id' => 'vim-a', 'last_refreshed_at' => now()->subWeek()]);
    conn($user, 'vimeo', ['resource_id' => 'vim-b', 'last_refreshed_at' => now()->subWeek()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class, 2);
    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->platform === 'youtube');
    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->platform === 'vimeo');
});

it('staggers dispatches monotonically within the configured window', function () {
    config()->set('partna.refresh.dispatch.max_per_platform', 10);
    config()->set('partna.refresh.dispatch.stagger_window_seconds', 100);
    config()->set('partna.refresh.dispatch.max_stagger_seconds', 10);
    $user = dispatchUser();
    for ($i = 0; $i < 10; $i++) {
        conn($user, 'youtube', ['resource_id' => "yt-{$i}", 'last_refreshed_at' => now()->subWeek()]);
    }

    $this->artisan('integrations:refresh')->assertSuccessful();

    $delays = Queue::pushed(RefreshConnectionJob::class)
        ->map(fn ($j) => $j->delay === null ? 0 : now()->diffInSeconds($j->delay))
        ->values();

    expect($delays->count())->toBe(10);
    expect($delays->first())->toBe(0);
    // Non-decreasing.
    expect($delays->sort()->values()->all())->toBe($delays->all());
    // Bounded by the window.
    expect($delays->max())->toBeLessThanOrEqual(100);
    // Distinct-ish: spacing is min(10, 100/10) = 10s apart, so all 10 differ.
    expect($delays->unique()->count())->toBe(10);
});

it('keeps the stagger window well below RefreshConnectionJob::$uniqueFor', function () {
    // A stagger window >= $uniqueFor would let a delayed job's unique lock expire
    // before it ever runs, letting the next hourly run dispatch a duplicate.
    expect(config('partna.refresh.dispatch.stagger_window_seconds'))
        ->toBeLessThan((new RefreshConnectionJob('x', 'youtube'))->uniqueFor);
});

it('uses one run-global stagger index, not a per-platform one', function () {
    config()->set('partna.refresh.dispatch.max_per_platform', 10);
    config()->set('partna.refresh.dispatch.stagger_window_seconds', 100);
    config()->set('partna.refresh.dispatch.max_stagger_seconds', 10);
    $user = dispatchUser();
    conn($user, 'youtube', ['resource_id' => 'yt-a', 'last_refreshed_at' => now()->subWeek()]);
    conn($user, 'youtube', ['resource_id' => 'yt-b', 'last_refreshed_at' => now()->subWeek()]);
    conn($user, 'vimeo', ['resource_id' => 'vim-a', 'last_refreshed_at' => now()->subWeek()]);
    conn($user, 'vimeo', ['resource_id' => 'vim-b', 'last_refreshed_at' => now()->subWeek()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    $zeroDelayCount = Queue::pushed(RefreshConnectionJob::class)
        ->filter(fn ($j) => $j->delay === null)
        ->count();

    // If the index reset per platform, both platforms' first job would land at
    // delay 0 — exactly two zero-delay jobs instead of one.
    expect($zeroDelayCount)->toBe(1);
});

it('dispatches immediately when the stagger window is zero', function () {
    config()->set('partna.refresh.dispatch.max_per_platform', 5);
    config()->set('partna.refresh.dispatch.stagger_window_seconds', 0);
    $user = dispatchUser();
    for ($i = 0; $i < 5; $i++) {
        conn($user, 'youtube', ['resource_id' => "yt-{$i}", 'last_refreshed_at' => now()->subWeek()]);
    }

    $this->artisan('integrations:refresh')->assertSuccessful();

    $allImmediate = Queue::pushed(RefreshConnectionJob::class)->every(fn ($j) => $j->delay === null);
    expect($allImmediate)->toBeTrue();
});

it('converges on the over-cap remainder on a later run instead of starving it', function () {
    config()->set('partna.refresh.dispatch.max_per_platform', 1);
    $user = dispatchUser();
    $older = conn($user, 'youtube', ['resource_id' => 'yt-older', 'last_refreshed_at' => now()->subDays(2)]);
    $newer = conn($user, 'youtube', ['resource_id' => 'yt-newer', 'last_refreshed_at' => now()->subDay()]);

    $this->artisan('integrations:refresh')->assertSuccessful();
    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $older->id);
    Queue::assertNotPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $newer->id);

    // Simulate the staggered job for $older having completed successfully —
    // it is no longer due, so run 2 must pick up $newer instead of re-selecting
    // $older or starving $newer a second time.
    $older->update(['last_refreshed_at' => now()]);

    $this->artisan('integrations:refresh')->assertSuccessful();
    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $newer->id);
});

it('pins the integrations:refresh schedule slot off the collision minute', function () {
    // A naive str_contains() match on $event->command also matches
    // integrations:refresh-backlog (it's a prefix substring) — anchor on the
    // trailing command name instead.
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_ends_with(trim((string) ($e->command ?? '')), 'integrations:refresh'));

    expect($event)->not->toBeNull('integrations:refresh is not registered in the scheduler');
    expect($event->expression)->toBe('23 * * * *');
});
