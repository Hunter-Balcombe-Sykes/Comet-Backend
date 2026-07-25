<?php

// LIFE-5: UpdateSiteAction must enforce the subdomain cooldown against the
// DB-committed subdomain_changed_at value, not the stale pre-transaction
// in-memory snapshot loaded by loadMissing('site') before the tx opens.
//
// The race: two concurrent requests each call loadMissing('site') before
// either enters the transaction. If the first rename commits (setting
// subdomain_changed_at = now()), the second's in-memory snapshot still shows
// the OLD value (null or >30 days ago), so its cooldown check passes
// incorrectly — both renames succeed. The fix (LIFE-5) re-reads
// subdomain_changed_at under a FOR UPDATE row lock INSIDE the transaction,
// overwriting the stale model attribute before the cooldown if-branch fires.
//
// This test simulates the stale-snapshot scenario in a single process:
//   1. The DB row has subdomain_changed_at = now() (cooldown NOT elapsed).
//   2. The in-memory $professional->site->subdomain_changed_at is null
//      (simulating the pre-tx snapshot that preceded the first rename).
//   3. Calling execute() MUST throw a ValidationException because the action
//      now re-reads the DB value under lock and sees the recent timestamp.

use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();

    // Stub the cache service — the action doesn't need Redis here.
    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('forgetBrandDesign')->andReturnNull()->byDefault();
    app()->instance(SiteCacheService::class, $cache);
});

it('enforces the cooldown against the DB-committed subdomain_changed_at, not the stale in-memory model snapshot', function () {
    config(['partna.handle.subdomain_cooldown_days' => 30]);

    Carbon::setTestNow('2026-06-14 10:00:00');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    // DB row has subdomain_changed_at = right now — cooldown NOT elapsed.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'current-handle',
        'handle_lc' => 'current-handle',
        'display_name' => 'Test Pro',
        'first_name' => 'Test',
        'primary_email' => 'testpro@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'current-handle',
        // Recent change timestamp in the DB — cooldown enforces a block.
        'subdomain_changed_at' => $now,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Load the professional, then corrupt the in-memory site snapshot to
    // simulate the stale pre-transaction value that a concurrent request would
    // see (null = "never changed" → cooldown check would pass incorrectly).
    $pro = User::query()->with('site')->findOrFail($proId);
    $pro->site->subdomain_changed_at = null; // stale snapshot — pre-fix this would bypass cooldown

    // The action MUST re-read subdomain_changed_at from the DB under FOR UPDATE
    // and see the recent timestamp, then throw the cooldown ValidationException.
    expect(fn () => app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'a-new-handle']))
        ->toThrow(ValidationException::class, 'You can change your subdomain again on');
});
