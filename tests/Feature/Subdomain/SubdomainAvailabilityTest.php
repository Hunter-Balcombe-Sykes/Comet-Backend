<?php

/** @phpstan-ignore-all */

use App\Models\Core\User\User;
use App\Services\Site\SubdomainAvailabilityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

// Self-contained schema setup (parallel-safe — does not depend on another
// test file's local helpers). Users/sites come from the shared Pest helpers;
// the two alias tables are created here with the same shapes the production
// migrations define.
function setupAvailabilitySchema(): void
{
    setupUsersTable();
    setupSitesTable();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
        id TEXT PRIMARY KEY,
        site_id TEXT NOT NULL,
        subdomain TEXT NOT NULL,
        reclaim_until TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NOT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.user_handle_aliases (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        handle TEXT NOT NULL,
        reclaim_until TEXT NULL,
        expires_at TEXT NULL,
        notified_t3_at TEXT NULL,
        notified_t1_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

function availabilityUser(string $handle, ?string $subdomain = null, ?string $changedAt = null): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    if ($subdomain !== null) {
        DB::table('site.sites')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'subdomain' => $subdomain,
            'subdomain_changed_at' => $changedAt,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    return $user->fresh();
}

beforeEach(function () {
    setupAvailabilitySchema();

    DB::table('core.user_handle_aliases')->delete();
    DB::table('site.site_subdomain_aliases')->delete();
    DB::table('site.sites')->delete();
    DB::table('core.users')->delete();

    $this->service = app(SubdomainAvailabilityService::class);
})->group('subdomain');

it('reports an unused subdomain as available', function () {
    $check = $this->service->check('brandnew');

    expect($check['available'])->toBeTrue()
        ->and($check['reason'])->toBeNull()
        ->and($check['subdomain'])->toBe('brandnew');
});

it('normalises case and whitespace', function () {
    expect($this->service->check('  MixedCase ')['subdomain'])->toBe('mixedcase');
});

it('rejects invalid formats', function () {
    expect($this->service->check('ab')['reason'])->toBe(SubdomainAvailabilityService::REASON_INVALID)
        ->and($this->service->check('-bad')['reason'])->toBe(SubdomainAvailabilityService::REASON_INVALID)
        ->and($this->service->check('bad-')['reason'])->toBe(SubdomainAvailabilityService::REASON_INVALID)
        ->and($this->service->check('has space')['reason'])->toBe(SubdomainAvailabilityService::REASON_INVALID)
        ->and($this->service->check(str_repeat('a', 64))['reason'])->toBe(SubdomainAvailabilityService::REASON_INVALID);
});

it('reports the caller\'s current subdomain as own_current', function () {
    $check = $this->service->check('myhandle', null, null, 'MyHandle');

    expect($check['available'])->toBeFalse()
        ->and($check['reason'])->toBe(SubdomainAvailabilityService::REASON_OWN_CURRENT);
});

it('rejects reserved subdomains', function () {
    config()->set('partna.reserved_subdomains', ['admin', 'www']);

    expect($this->service->check('admin')['reason'])->toBe(SubdomainAvailabilityService::REASON_RESERVED);
});

it('rejects a subdomain taken by another site but excludes the caller\'s own site', function () {
    $other = availabilityUser('other', 'claimed');
    $ownSiteId = (string) DB::table('site.sites')->where('subdomain', 'claimed')->value('id');

    expect($this->service->check('claimed')['reason'])->toBe(SubdomainAvailabilityService::REASON_TAKEN)
        ->and($this->service->check('CLAIMED')['reason'])->toBe(SubdomainAvailabilityService::REASON_TAKEN)
        // Excluding the owning site (the caller's own) makes it available again.
        ->and($this->service->check('claimed', $ownSiteId)['available'])->toBeTrue();
});

it('holds subdomains with an active alias and releases expired ones', function () {
    Carbon::setTestNow('2026-07-01');

    DB::table('site.site_subdomain_aliases')->insert([
        ['id' => (string) Str::uuid(), 'site_id' => (string) Str::uuid(), 'subdomain' => 'recentlyfreed',
            'expires_at' => Carbon::now()->addDays(60)->toDateTimeString(), 'created_at' => Carbon::now()->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'site_id' => (string) Str::uuid(), 'subdomain' => 'longgone',
            'expires_at' => Carbon::now()->subDay()->toDateTimeString(), 'created_at' => Carbon::now()->subDays(100)->toDateTimeString()],
    ]);

    expect($this->service->check('recentlyfreed')['reason'])->toBe(SubdomainAvailabilityService::REASON_HELD)
        ->and($this->service->check('longgone')['available'])->toBeTrue();

    Carbon::setTestNow();
});

it('reports the caller\'s own held alias as own_alias', function () {
    Carbon::setTestNow('2026-07-01');

    $siteId = (string) Str::uuid();
    DB::table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId, 'subdomain' => 'oldme',
        'expires_at' => Carbon::now()->addDays(60)->toDateTimeString(), 'created_at' => Carbon::now()->toDateTimeString(),
    ]);

    expect($this->service->check('oldme', null, null, null, $siteId)['reason'])
        ->toBe(SubdomainAvailabilityService::REASON_OWN_ALIAS)
        ->and($this->service->check('oldme')['reason'])->toBe(SubdomainAvailabilityService::REASON_HELD);

    Carbon::setTestNow();
});

it('holds handles aliased by another user but not the caller\'s own', function () {
    Carbon::setTestNow('2026-07-01');

    DB::table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(), 'user_id' => 'user-other', 'handle' => 'exhandle',
        'expires_at' => Carbon::now()->addDays(60)->toDateTimeString(), 'created_at' => Carbon::now()->toDateTimeString(),
    ]);

    expect($this->service->check('exhandle')['reason'])->toBe(SubdomainAvailabilityService::REASON_HELD)
        ->and($this->service->check('exhandle', null, 'user-other')['available'])->toBeTrue();

    Carbon::setTestNow();
});

it('fails open and reports the exception when the handle-alias query throws', function () {
    Exceptions::fake();

    // Force a real QueryException out of the core.user_handle_aliases query
    // (simulating a transient DB failure) by dropping the table out from under
    // it — no DB mocking needed. beforeEach's CREATE TABLE IF NOT EXISTS
    // restores it for the next test regardless of run order.
    DB::connection('pgsql')->statement('DROP TABLE core.user_handle_aliases');

    $check = $this->service->check('brandnew');

    // Fail-open: degrades to "not held" instead of propagating the error,
    // mirroring UpdateSiteRequest's contract.
    expect($check['available'])->toBeTrue()
        ->and($check['reason'])->toBeNull();

    Exceptions::assertReported(QueryException::class);
});

it('reports the change window for a user inside the cooldown', function () {
    Carbon::setTestNow('2026-07-01');
    config()->set('partna.handle.subdomain_cooldown_days', 30);

    $user = availabilityUser('cooluser', 'coolsite', Carbon::now()->subDays(10)->toDateTimeString());
    $result = $this->service->checkForUser($user, 'somethingnew');

    expect($result['available'])->toBeTrue()
        ->and($result['can_change'])->toBeFalse()
        ->and($result['next_change_available_at'])->not->toBeNull();

    Carbon::setTestNow();
});

it('reports an open change window once the cooldown has lapsed', function () {
    Carbon::setTestNow('2026-07-01');
    config()->set('partna.handle.subdomain_cooldown_days', 30);

    $user = availabilityUser('freeuser', 'freesite', Carbon::now()->subDays(40)->toDateTimeString());
    $result = $this->service->checkForUser($user, 'somethingnew');

    expect($result['can_change'])->toBeTrue()
        ->and($result['next_change_available_at'])->toBeNull();

    Carbon::setTestNow();
});

it('serves the availability endpoint for an authenticated user', function () {
    $user = availabilityUser('endpointuser', 'endpointsite');
    availabilityUser('rival', 'wanted');

    actingAsUser($user)->getJson('/api/site/subdomain-availability?subdomain=wanted')
        ->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('reason', 'taken')
        ->assertJsonPath('can_change', true);

    actingAsUser($user)->getJson('/api/site/subdomain-availability?subdomain=greenfield')
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('reason', null);

    actingAsUser($user)->getJson('/api/site/subdomain-availability')
        ->assertStatus(422);
});
