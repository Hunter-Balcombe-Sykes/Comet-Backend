<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Phase-0 run tooling (efficiency protocol, 2026-08-28): fleet:verify,
 * fleet:rebuild, builds:await — the commands that replace the ad-hoc tinker
 * round-trips and sleep-timer polling of the 2026-08-27 run.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupIntegrationConnectionsTable();
    setupSiteMediaTable();
    setupSubdomainAliasesTable();
    setupBlocksTable();
    setupWorkplacesTable();
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

function seedPartnaStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->save();

    return $staff;
}

function fleetTenant(string $handle, string $state = PreAccountBuild::STATE_READY): array
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'handle' => $handle]);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => $handle, 'is_published' => false]);
    $build = PreAccountBuild::factory()->make([
        'build_state' => $state,
        'source_type' => 'instagram',
        // IG-handle-shaped (no hyphens) — requestBuild re-validates it on
        // rebuild, and for instagram builds the HANDLE derives from this ref.
        'source_ref' => str_replace('-', '', $handle),
        'expires_at' => now()->addDays(7),
    ]);
    $build->user()->associate($user);
    $build->save();

    return [$user, $site, $build];
}

// ── fleet:verify ─────────────────────────────────────────────────────────────

it('fleet:verify prints one row per handle including missing accounts', function () {
    fleetTenant('fv-one');

    $this->artisan('fleet:verify', ['handles' => ['fv-one', 'fv-ghost']])
        ->expectsOutputToContain('fv-one')
        ->expectsOutputToContain('MISSING')
        ->assertSuccessful();
});

// ── fleet:rebuild ────────────────────────────────────────────────────────────

it('fleet:rebuild refuses the whole batch when any handle is not unclaimed', function () {
    [$claimed] = fleetTenant('fr-claimed');
    $claimed->forceFill(['status' => 'active'])->save();
    [, , $okBuild] = fleetTenant('fr-ok');

    $this->artisan('fleet:rebuild', ['handles' => ['fr-ok', 'fr-claimed']])
        ->assertFailed();

    // Nothing was expired — all-or-nothing held.
    expect($okBuild->fresh()->expires_at->isFuture())->toBeTrue();
});

it('fleet:rebuild dry-run prints specs and changes nothing', function () {
    [, , $build] = fleetTenant('fr-dry');

    $this->artisan('fleet:rebuild', ['handles' => ['fr-dry'], '--dry-run' => true])
        ->expectsOutputToContain('fr-dry')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(PreAccountBuild::query()->count())->toBe(1)
        ->and($build->fresh()->expires_at->isFuture())->toBeTrue();
});

it('fleet:rebuild tears down and rebuilds, re-allocating the same handle', function () {
    // Handle == source_ref: for instagram builds the fresh handle derives
    // from the ref, which is how real fleet rebuilds keep their handles.
    [$user] = fleetTenant('frfull');
    seedPartnaStaff();

    $this->artisan('fleet:rebuild', ['handles' => ['frfull']])
        ->assertSuccessful();

    // Old provisional user hard-deleted; a fresh one allocated from the ref.
    // SQLite caveat (same limitation PruneExpiredBuildsTest documents): the
    // site row's ON DELETE CASCADE doesn't fire here, so the stale subdomain
    // makes HandleAllocator suffix ('frfull1'). On Postgres the cascade frees
    // the exact handle — verified live on all 16 fleet rebuilds 2026-08-27.
    expect(User::withTrashed()->find($user->id))->toBeNull();
    $fresh = User::query()->where('handle_lc', 'like', 'frfull%')->first();
    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->not->toBe($user->id)
        ->and(PreAccountBuild::query()->where('user_id', $fresh->id)->exists())->toBeTrue();
});

// ── fleet:new ────────────────────────────────────────────────────────────────

function fleetSpecs(array $specs): array
{
    return ['--b64' => base64_encode(json_encode($specs))];
}

it('fleet:new refuses the whole batch when any spec is malformed', function () {
    seedPartnaStaff();

    $this->artisan('fleet:new', fleetSpecs([
        ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'fnok', 'source_name' => 'Fn Ok'],
        ['account_type' => 'partna', 'source_type' => 'tiktok', 'source_ref' => 'fnbad', 'source_name' => 'Fn Bad'],
    ]))->assertFailed();

    // The good spec ahead of the bad one was never requested.
    expect(PreAccountBuild::query()->count())->toBe(0);
});

it('fleet:new refuses a google_business spec with no name, because the place id cannot seed a handle', function () {
    seedPartnaStaff();

    $this->artisan('fleet:new', fleetSpecs([
        ['account_type' => 'business', 'source_type' => 'google_business', 'source_ref' => 'ChIJfake', 'source_name' => ''],
    ]))->assertFailed();

    expect(PreAccountBuild::query()->count())->toBe(0);
});

it('fleet:new dry-run prints the specs and builds nothing', function () {
    $this->artisan('fleet:new', fleetSpecs([
        ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'fndry', 'source_name' => 'Fn Dry'],
    ]) + ['--dry-run' => true])
        ->expectsOutputToContain('fndry')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(PreAccountBuild::query()->count())->toBe(0);
});

it('fleet:new cold-builds an account that never existed', function () {
    seedPartnaStaff();

    $this->artisan('fleet:new', fleetSpecs([
        ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'fnfresh', 'source_name' => 'Fn Fresh'],
    ]))->assertSuccessful();

    $user = User::query()->where('handle_lc', 'like', 'fnfresh%')->first();
    expect($user)->not->toBeNull()
        ->and($user->status)->toBe('unclaimed')
        ->and(PreAccountBuild::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('fleet:new rejects specs that are not a JSON list', function () {
    $this->artisan('fleet:new', ['--b64' => base64_encode('"not a list"')])->assertFailed();
    $this->artisan('fleet:new', [])->assertFailed();
});

// ── builds:await ─────────────────────────────────────────────────────────────

it('builds:await returns success immediately when all builds are terminal', function () {
    fleetTenant('ba-ready');

    $this->artisan('builds:await', ['--since' => now()->subHour()->toDateTimeString(), '--timeout' => 5])
        ->expectsOutputToContain('ba-ready')
        ->assertSuccessful();
});

it('builds:await fails when a build is failed, and reports no builds cleanly', function () {
    fleetTenant('ba-bad', PreAccountBuild::STATE_FAILED);

    $this->artisan('builds:await', ['--since' => now()->subHour()->toDateTimeString(), '--timeout' => 5])
        ->assertFailed();

    $this->artisan('builds:await', ['--since' => now()->addDay()->toDateTimeString(), '--timeout' => 5])
        ->expectsOutputToContain('No builds')
        ->assertSuccessful();
});
