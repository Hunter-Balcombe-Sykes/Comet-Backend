<?php

// #CCH-1: IntegrationConnectionObserver::enableContentInstagramAuto() strips a
// lingering auto_sync_latest=false via a raw DB::table()->update() — it
// bypasses Eloquent, so saved()'s own wasChanged('display_settings') gate
// never sees the strip and only the EARLIER purge (fired on wasRecentlyCreated,
// before the strip ran) goes out, reflecting pre-strip settings. Fix: re-run
// the edge purge against the refreshed row inside enableContentInstagramAuto()
// itself.
//
// Asserted via a mocked IntegrationConnectionCacheRefresher, not
// Queue::assertPushed(CloudflareCachePurgeJob::class, 2): CloudflareCachePurgeJob
// is ShouldBeUnique with a 35s coalesce window (its own docblock: "duplicate
// dispatches from the same request's observer cascade are dropped"), and that
// lock is acquired at PendingDispatch::shouldDispatch() time against the REAL
// cache store — Queue::fake() does not bypass it. Both purges in this test's
// scenario carry the SAME uniqueId() (same handle, same request), so the
// second dispatch is coalesced away by design and would never show up in
// Queue::pushed() count — asserting on it would make this test require
// something the codebase's OWN cache-purge job deliberately prevents. What we
// can and must pin is that OUR observer code still makes the second call —
// that is what CLAUDE.md's "explicit invalidation" rule requires of a write
// that bypasses Eloquent, independent of whether the downstream job
// ultimately coalesces it in a given timing window.

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('calls the cache refresher a second time, against the refreshed row, when connecting Instagram strips a lingering auto_sync_latest=false (#CCH-1)', function () {
    $user = createTenant('cch1-ig-strip');

    $mock = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $mock->shouldReceive('refresh')->twice()->with(Mockery::on(
        fn (IntegrationConnection $c) => $c->user_id === $user->id
    ));
    app()->instance(IntegrationConnectionCacheRefresher::class, $mock);

    // A fresh Instagram connect with a stale false already on the row — the
    // shape enableContentInstagramAuto() strips. wasRecentlyCreated alone
    // fires the FIRST refresh() call (before the strip runs); the fix's
    // second call is what this test pins.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'acme'],
        'display_settings' => ['auto_sync_latest' => false],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $row = DB::connection('pgsql')->table('site.platform_connections')
        ->where('user_id', $user->id)->where('platform', 'instagram')->first();
    expect(json_decode((string) $row->display_settings, true) ?? [])->not->toHaveKey('auto_sync_latest');
});

it('calls the cache refresher only once when there is no lingering false to strip', function () {
    $user = createTenant('cch1-ig-nostrip');

    $mock = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $mock->shouldReceive('refresh')->once();
    app()->instance(IntegrationConnectionCacheRefresher::class, $mock);

    // No display_settings at all — nothing for enableContentInstagramAuto() to
    // strip, so only the wasRecentlyCreated call fires.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'acme'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
});
