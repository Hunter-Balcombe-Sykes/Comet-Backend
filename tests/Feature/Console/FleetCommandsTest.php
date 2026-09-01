<?php

use App\Console\Commands\FleetAssertCommand;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

// #W1-PRIV-4: contact routing diagnostics only need on/off, not the address.
it('fleet:verify masks the contact email instead of printing it in full', function () {
    [$user, $site] = fleetTenant('fv-contact');

    DB::table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'site_id' => $site->id,
        'block_group' => Block::GROUP_SECTIONS,
        'block_type' => 'contact',
        'is_enabled' => 1,
        'settings' => json_encode(['notification_email' => 'jane@example.com']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $output = $this->artisan('fleet:verify', ['handles' => ['fv-contact']])
        ->assertSuccessful();

    $output->expectsOutputToContain('j***@example.com');
    $output->doesntExpectOutputToContain('jane@example.com');
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

it('fleet:rebuild tears down and queues a fresh scrape-first build', function () {
    [$user] = fleetTenant('frfull');
    seedPartnaStaff();

    $this->artisan('fleet:rebuild', ['handles' => ['frfull']])
        ->expectsOutputToContain('queued')
        ->assertSuccessful();

    // Old provisional user hard-deleted. Item 1a/1d: the fresh build has NO
    // user yet — identity (and the handle, off the scraped display name via
    // the same ladder public signups use) materializes inside the queued job.
    expect(User::withTrashed()->find($user->id))->toBeNull();
    $fresh = PreAccountBuild::query()->where('source_ref', 'frfull')->latest('created_at')->first();
    expect($fresh)->not->toBeNull()
        ->and($fresh->user_id)->toBeNull()
        ->and($fresh->account_type)->not->toBeNull();
    Queue::assertPushed(GeneratePreAccountSiteJob::class);
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
    ]))
        ->expectsOutputToContain('queued')
        ->assertSuccessful();

    // Item 1a/1d: no user at request time — the build row carries the
    // identity facts and the queued job materializes the user after the
    // scrape verifies the source.
    $build = PreAccountBuild::query()->where('source_ref', 'fnfresh')->first();
    expect($build)->not->toBeNull()
        ->and($build->user_id)->toBeNull()
        ->and($build->account_type)->toBe('partna')
        ->and($build->source_name)->toBe('Fn Fresh');
    Queue::assertPushed(GeneratePreAccountSiteJob::class);
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

// ── fleet:assert ─────────────────────────────────────────────────────────────
//
// The estate-invariant counterpart to fleet:verify. These pin the two things
// that make it useful rather than decorative: the ratchet only fires on a
// REGRESSION, and the absolute checks refuse to be baselined at all.

function fleetAssertBaselinePath(): string
{
    return sys_get_temp_dir().'/fleet-assert-'.Str::uuid().'.json';
}

it('fleet:assert ratchets — a recorded estate holds, and one more offender breaks it', function () {
    $path = fleetAssertBaselinePath();
    User::factory()->count(2)->create();

    $this->artisan('fleet:assert', ['--baseline' => $path, '--update-baseline' => true])->assertSuccessful();
    // Same estate, same numbers: the backlog we already know about must not
    // shout every night, or nobody reads the check.
    $this->artisan('fleet:assert', ['--baseline' => $path])->assertSuccessful();

    User::factory()->create();

    $this->artisan('fleet:assert', ['--baseline' => $path])->assertFailed();
});

it('fleet:assert has no baseline tolerance for an owned, unpublished site that still serves', function () {
    Http::fake(['*' => Http::response('', 200, ['cache-control' => 'public, max-age=15, s-maxage=26'])]);

    $user = User::factory()->create(['status' => 'active']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'fa-owned', 'is_published' => false]);

    $path = fleetAssertBaselinePath();
    $this->artisan('fleet:assert', ['--baseline' => $path, '--update-baseline' => true, '--http' => true]);

    // Recording a baseline WITH the exposure present must not excuse it —
    // that is the whole reason publish_gate_exposed is absolute, not ratcheted.
    $this->artisan('fleet:assert', ['--baseline' => $path, '--http' => true])->assertFailed();
});

it('fleet:assert does not fault an UNCLAIMED build for serving while unpublished', function () {
    // The pre-claim demo is the product pitch and the 2026-08-25 owner ruling
    // reverted the gate that closed it. A future session must not "fix" this.
    Http::fake(['*' => Http::response('', 200, ['cache-control' => 'public, s-maxage=26'])]);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'fa-unclaimed', 'is_published' => false]);

    $path = fleetAssertBaselinePath();
    $this->artisan('fleet:assert', ['--baseline' => $path, '--update-baseline' => true, '--http' => true]);

    $this->artisan('fleet:assert', ['--baseline' => $path, '--http' => true])->assertSuccessful();
});

it('fleet:assert flags an edge s-maxage far above what the application asks for', function () {
    Http::fake(['*' => Http::response('', 200, ['cache-control' => 'public, max-age=15, s-maxage=86400'])]);

    $user = User::factory()->create();
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'fa-stale', 'is_published' => true]);

    $path = fleetAssertBaselinePath();
    $this->artisan('fleet:assert', ['--baseline' => $path, '--update-baseline' => true, '--http' => true]);

    $this->artisan('fleet:assert', ['--baseline' => $path, '--http' => true])->assertFailed();
});

// The name rule, taught by the live estate: two failure classes, and one
// legitimate accented name that must survive both of them.
it('fleet:assert name rule rejects blanks, handles, emoji and styled letterforms', function () {
    expect(FleetAssertCommand::nameIsUnusable('', 'someone'))->toBeTrue()
        ->and(FleetAssertCommand::nameIsUnusable('   ', 'someone'))->toBeTrue()
        ->and(FleetAssertCommand::nameIsUnusable('playlunch', 'playlunch'))->toBeTrue()
        ->and(FleetAssertCommand::nameIsUnusable('PlayLunch', 'playlunch'))->toBeTrue()
        ->and(FleetAssertCommand::nameIsUnusable('🍎PLAYLUNCH', 'playlunch'))->toBeTrue()
        ->and(FleetAssertCommand::nameIsUnusable('ʙᴇɴ', 'benwardscissorhands'))->toBeTrue();
});

it('fleet:assert name rule accepts ordinary names, accented ones included', function () {
    expect(FleetAssertCommand::nameIsUnusable('Biànca Restaurant', 'bianca-restaurant'))->toBeFalse()
        ->and(FleetAssertCommand::nameIsUnusable('José', 'jose-p'))->toBeFalse()
        ->and(FleetAssertCommand::nameIsUnusable('Emma', 'emdinonhair'))->toBeFalse();
});

function fleetAssertConnection(string $userId, string $placeId): void
{
    $connection = new IntegrationConnection([
        'surface_key' => 'google_business.listing', 'routing_class' => 'listing',
        'resource_id' => 'google-business', 'payload' => [], 'is_active' => true,
    ]);
    $connection->user_id = $userId;
    $connection->platform = 'google-business';
    $connection->place_id = $placeId;
    $connection->save();
}

it('fleet:assert counts duplicated place ids as GROUPS, not as rows', function () {
    // The aggregate has to sit outside the GROUP BY: ->count() on a grouped
    // builder counts rows per group, so two connections sharing one place id
    // must read as ONE duplicated place, never as two.
    fleetAssertConnection(User::factory()->create()->id, 'place-shared');
    fleetAssertConnection(User::factory()->create()->id, 'place-shared');
    fleetAssertConnection(User::factory()->create()->id, 'place-unique');

    $path = fleetAssertBaselinePath();
    $this->artisan('fleet:assert', ['--baseline' => $path, '--update-baseline' => true])->assertSuccessful();

    expect(json_decode((string) file_get_contents($path), true)['duplicate_place_id'])->toBe(1);
});
