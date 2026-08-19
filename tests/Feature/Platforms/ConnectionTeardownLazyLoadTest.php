<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Observers\Core\IntegrationConnectionObserver;

/**
 * Measured live on dev 2026-08-19: `builds:prune-expired` reported
 * "Pruned 0 of 1 (1 failed, will retry next run)" while every row was in fact
 * deleted, logging
 *
 *   "Attempted to lazy load [user] on model [IntegrationConnection] but lazy
 *    loading is disabled."
 *
 * IntegrationConnectionObserver::deleted() rolls site.updated_at through
 * $connection->user?->site?->touch(). That walks TWO unloaded relations, which
 * Model::preventLazyLoading (on everywhere except production) refuses.
 *
 * WHY THIS TEST CALLS THE OBSERVER DIRECTLY rather than driving the command:
 * the observer is $afterCommit = true, and every test in this suite runs inside
 * a wrapping transaction that never commits — so afterCommit observers never
 * fire and the defect is structurally invisible end-to-end. Driving
 * `builds:prune-expired` here passes whether or not the bug is present, which
 * is exactly how it survived to production-adjacent code unnoticed. Calling the
 * hook directly is the only honest reproduction available in this lane.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
});

it('does not lazy-load the user when a completeness-gated connection is deleted', function () {
    $user = User::factory()->create();
    Site::factory()->create(['user_id' => $user->id]);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha', // fresha carries a completeness predicate — the gate gating the lazy load
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme'],
        'is_active' => true,
    ]);

    // Re-fetched, so `user` is deliberately NOT eager-loaded — the teardown shape.
    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();

    app(IntegrationConnectionObserver::class)->deleted($connection);
})->throwsNoExceptions();

it('still rolls site.updated_at on that delete', function () {
    // The lazy-load fix must not silently drop the cache-key rotation it guards:
    // a completeness-gated platform would otherwise keep advertising page
    // presence from a connection that is gone.
    $user = User::factory()->create();
    $site = Site::factory()->create(['user_id' => $user->id]);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme'],
        'is_active' => true,
    ]);

    $before = now()->subMinutes(10);
    Site::query()->whereKey($site->id)->update(['updated_at' => $before]);

    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();
    app(IntegrationConnectionObserver::class)->deleted($connection);

    expect(Site::query()->whereKey($site->id)->value('updated_at'))
        ->not->toEqual($before);
});

it('does not throw when the user is already gone', function () {
    // The real teardown order deletes connections while the user row is on its
    // way out; a missing user must be a no-op, not a fault that costs the
    // command its return value.
    $user = User::factory()->create();
    Site::factory()->create(['user_id' => $user->id]);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme'],
        'is_active' => true,
    ]);

    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();
    User::query()->whereKey($user->id)->forceDelete();

    app(IntegrationConnectionObserver::class)->deleted($connection);
})->throwsNoExceptions();
