<?php

// LIFE-3: UpdateSiteAction must merge the settings JSONB against a locked
// re-read of the on-disk value, not the pre-transaction in-memory snapshot
// loaded by loadMissing('site') before the tx opens.
//
// The race: two concurrent PATCHes both call loadMissing('site') before
// either enters the transaction. If the first PATCH commits a settings key
// the second's in-memory snapshot never saw, the second's merge (against its
// stale snapshot) silently drops that key when it computes its own settings
// blob and overwrites the whole column with only what it knew about. The fix
// (LIFE-3) re-reads the on-disk settings under a FOR UPDATE row lock INSIDE
// the transaction and merges against THAT, not the stale model attribute.
//
// This test simulates the stale-snapshot scenario in a single process:
//   1. The DB row has settings = {"gallery": true, "booking_note": "from-db"}
//      — as if a concurrent request already committed booking_note.
//   2. The in-memory $pro->site->settings is corrupted to ['gallery' => true]
//      (missing booking_note), simulating the pre-transaction snapshot that
//      preceded the concurrent write.
//   3. Calling execute() with an unrelated settings key MUST still preserve
//      booking_note in the final DB row (pre-fix this would be dropped —
//      the merge would only see 'gallery' as the "existing" side).

use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();

    // Stub the cache service so the SiteObserver's invalidateSite() on save is a
    // no-op (no Redis needed), and fake the queue
    // so the observer's KV-sync / cache-warm dispatches don't run.
    $cache = Mockery::mock(SiteCacheService::class)->shouldIgnoreMissing();
    app()->instance(SiteCacheService::class, $cache);
    Queue::fake();
});

it('merges settings against a locked re-read of the on-disk value, not the stale in-memory snapshot', function () {
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'stale-owner',
        'handle_lc' => 'stale-owner',
        'display_name' => 'Stale Owner',
        'first_name' => 'Stale',
        'primary_email' => 'stale@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // DB row already carries booking_note — as if a concurrent PATCH
    // committed it after this process took its in-memory snapshot.
    // gallery/booking_note are plain (non-promoted, non-list) settings keys.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'stale-owner',
        'settings' => json_encode(['gallery' => true, 'booking_note' => 'from-db']),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $pro = User::query()->with('site')->findOrFail($proId);
    // Corrupt the in-memory snapshot to simulate a stale pre-transaction read
    // — missing booking_note, as this process would have seen it before the
    // "concurrent" write committed.
    $pro->site->settings = ['gallery' => true];

    // A real KNOWN, non-promoted settings key — #W2-SEC-6 now drops anything
    // else from the INCOMING side of the merge, but that's orthogonal to what
    // this test is proving (the locked re-read vs. the stale snapshot).
    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => ['display_gallery_page' => false],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->first();
    $settings = json_decode($row->settings, true) ?? [];

    // Pre-fix: merging against the stale in-memory snapshot drops
    // booking_note entirely (it never appears in the "existing" side of the
    // merge). Post-fix: the locked re-read sees the real on-disk value and
    // preserves it alongside the newly-written key.
    expect($settings)->toMatchArray([
        'gallery' => true,
        'booking_note' => 'from-db',
        'display_gallery_page' => false,
    ]);
});

it('merges pool_order BY POOL — saving one pool\'s ordering mode leaves every other pool\'s alone', function () {
    // Found live 2026-08-24: the ordering fieldset PATCHes {pool_order: {services: manual}}
    // and the whole map was replaced, so watch silently fell back to newest.
    // pool_order is a MAP keyed by pool, not a list — the positional-merge
    // hazard LIST_SETTINGS_KEYS exists for does not apply to it.
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId, 'handle' => 'order-owner', 'handle_lc' => 'order-owner',
        'display_name' => 'Order Owner', 'first_name' => 'Order',
        'primary_email' => 'order@example.test', 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $proId, 'subdomain' => 'order-owner',
        'settings' => json_encode(['pool_order' => ['watch' => 'manual', 'listen' => 'smart']]),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $pro = User::query()->with('site')->findOrFail($proId);
    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => ['pool_order' => ['services' => 'manual', 'listen' => 'newest']],
    ]);

    $settings = json_decode(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('settings'), true);
    expect($settings['pool_order'])->toBe([
        'watch' => 'manual',
        'listen' => 'newest',
        'services' => 'manual',
    ]);
});

it('still replaces pool_locks atomically — a lock write carries the whole map', function () {
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId, 'handle' => 'lock-owner', 'handle_lc' => 'lock-owner',
        'display_name' => 'Lock Owner', 'first_name' => 'Lock',
        'primary_email' => 'lock@example.test', 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $proId, 'subdomain' => 'lock-owner',
        'settings' => json_encode(['pool_locks' => ['watch' => [['position' => 0, 'id' => 'a'], ['position' => 1, 'id' => 'b']]]]),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $pro = User::query()->with('site')->findOrFail($proId);
    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => ['pool_locks' => ['watch' => [['position' => 0, 'id' => 'b']]]],
    ]);

    $settings = json_decode(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('settings'), true);
    // Not [b, b] — the shorter incoming list must not inherit the old tail.
    expect($settings['pool_locks'])->toBe(['watch' => [['position' => 0, 'id' => 'b']]]);
});
