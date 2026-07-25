<?php

use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\RenameSubdomainAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();

    // Stub cache services — tests verify the action's own writes, not observer side-effects.
    // SiteCacheInvalidator is mocked to prevent UserObserver (afterCommit = true) from
    // calling $site->touch() after the transaction commits, which would persist the staged
    // subdomain before the caller's explicit save — the exact scenario these tests rule out.
    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('forgetBrandDesign')->andReturnNull()->byDefault();
    app()->instance(SiteCacheService::class, $cache);

    $invalidator = Mockery::mock(SiteCacheInvalidator::class);
    $invalidator->shouldReceive('touchSite')->andReturnNull()->byDefault();
    app()->instance(SiteCacheInvalidator::class, $invalidator);
});

/**
 * Seeds a user + site and returns [$user, $site].
 * Uses a unique prefix so repeated calls in one test don't collide.
 */
function seedRenameActionUser(string $subdomain): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => 'Test User',
        'first_name' => 'Test',
        'primary_email' => $userId.'@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $subdomain,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $user = User::query()->findOrFail($userId);
    $user->loadMissing('site');

    return [$user, $user->site];
}

it('returns immediately without staging any changes when the new subdomain equals the current one', function () {
    [$user, $site] = seedRenameActionUser('myhandle');

    DB::connection('pgsql')->transaction(function () use ($site, $user) {
        app(RenameSubdomainAction::class)->execute($site, 'myhandle', $user);
    });

    // Model must be unchanged — no staging occurred.
    expect($site->subdomain)->toBe('myhandle');
    expect($site->isDirty())->toBeFalse();
});

it('stages the new subdomain on the model without persisting to the database', function () {
    [$user, $site] = seedRenameActionUser('oldhandle');

    DB::connection('pgsql')->transaction(function () use ($site, $user) {
        app(RenameSubdomainAction::class)->execute($site, 'newhandle', $user);
    });

    // The model was staged with the new subdomain.
    expect($site->subdomain)->toBe('newhandle');

    // But the site DB row was NOT updated by the action itself — caller saves.
    $dbSubdomain = DB::connection('pgsql')
        ->table('site.sites')
        ->where('id', $site->id)
        ->value('subdomain');
    expect($dbSubdomain)->toBe('oldhandle');
});

// cache-edge-reconcile/LIFE-2: the lockForUpdate() read must cover BOTH subdomain
// and subdomain_changed_at, not just the latter. Simulates a rapid double-rename:
// the DB row already holds 'bob' (an earlier rename committed by a first request)
// while the in-memory $site the caller passes in still holds the stale 'alice' it
// was loaded with before that first rename. Before the fix, $current stayed
// 'alice', so the alias for 'bob' (the subdomain actually being renamed FROM) was
// never created and the audit log recorded the wrong old_handle.
it('re-reads subdomain under the lock so a stale in-memory value cannot skip its alias/audit entry', function () {
    [$user, $site] = seedRenameActionUser('alice');

    // A concurrent rename already committed 'bob' as the DB row's current
    // subdomain, but $site (loaded earlier) still holds 'alice' in memory.
    DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->update(['subdomain' => 'bob']);
    expect($site->subdomain)->toBe('alice'); // stale in-memory snapshot, unchanged

    DB::connection('pgsql')->transaction(function () use ($site, $user) {
        app(RenameSubdomainAction::class)->execute($site, 'carol', $user, ['allow_subdomain_override' => true]);
    });

    // The action must have renamed FROM the DB's actual current value ('bob'),
    // not the stale in-memory one ('alice').
    expect($site->subdomain)->toBe('carol');

    $alias = DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('site_id', $site->id)
        ->first();
    expect($alias)->not->toBeNull()
        ->and($alias->subdomain)->toBe('bob');

    $log = DB::connection('pgsql')->table('audit.handle_change_log')
        ->where('user_id', $user->id)
        ->first();
    expect($log->old_handle)->toBe('bob');
});
