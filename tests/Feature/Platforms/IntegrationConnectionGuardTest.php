<?php

use App\Exceptions\Platforms\TenantAnchorImmutableException;
use App\Exceptions\Platforms\UnregisteredPlatformException;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;

// Tests for the IntegrationConnection model's saving guard — the app-level
// replacement for the dropped platform_connections_platform_check DB constraint
// (commit c3ead5f1). The PlatformRegistry is now the gate; these tests confirm
// the guard fires on writes and is skipped on status-only updates.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections
});

function makeGuardTestUser(string $handle = 'guard-user'): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
    ]);
}

it('rejects create with an unregistered platform', function () {
    $user = makeGuardTestUser();

    expect(fn () => IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'myspace', // not in registry
        'resource_id' => 'ms-123',
        'payload' => [],
    ]))->toThrow(ValidationException::class);
});

it('accepts create with a registered platform (instagram)', function () {
    $user = makeGuardTestUser('guard-user-2');

    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'ig-handle',
        'payload' => [],
    ]);

    expect(IntegrationConnection::find($conn->id))->not->toBeNull();
    expect(IntegrationConnection::find($conn->id)->platform)->toBe('instagram');
});

it('accepts create via updateOrCreate with a registered platform', function () {
    $user = makeGuardTestUser('guard-user-3');

    $conn = IntegrationConnection::updateOrCreate(
        ['user_id' => $user->id, 'platform' => 'google-business'],
        ['resource_id' => 'gb-place-abc', 'payload' => ['name' => 'Test Cafe']]
    );

    expect($conn->exists)->toBeTrue();
    expect($conn->platform)->toBe('google-business');
});

it('rejects updateOrCreate with an unregistered platform', function () {
    $user = makeGuardTestUser('guard-user-4');

    expect(fn () => IntegrationConnection::updateOrCreate(
        ['user_id' => $user->id, 'platform' => 'myspace'],
        ['resource_id' => 'ms-123', 'payload' => []]
    ))->toThrow(ValidationException::class);
});

it('allows status-only update on an existing row without re-validating platform (isDirty refinement)', function () {
    $user = makeGuardTestUser('guard-user-5');

    // Create a valid row first.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'ig-handle-2',
        'payload' => [],
    ]);

    // Update only last_refresh_status — platform is not dirty, guard must skip.
    // This mirrors the refresh-cron write path; if the guard re-fired here it
    // would break every background refresh of existing connections.
    $conn->update(['last_refresh_status' => 'ok']);

    expect($conn->fresh()->last_refresh_status)->toBe('ok');
});

// SEC-3: guard-trip observability — ValidationException is in Laravel's
// $internalDontReport, so without an explicit report() call a guard-trip
// in a queued job is silent to Nightwatch.

it('reports UnregisteredPlatformException when an unregistered platform is saved', function () {
    Exceptions::fake();

    $user = makeGuardTestUser('guard-user-6');

    expect(fn () => IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'myspace', // not in registry
        'resource_id' => 'ms-123',
        'payload' => [],
    ]))->toThrow(ValidationException::class);

    // The dedicated exception must have been reported — this is the Nightwatch signal.
    Exceptions::assertReported(UnregisteredPlatformException::class);
});

it('does not report UnregisteredPlatformException when a valid platform is saved', function () {
    Exceptions::fake();

    $user = makeGuardTestUser('guard-user-7');

    // The saving guard resolves PlatformRegistry on first connection save, which
    // can eagerly wire scrapers. Instagram has no scraper dependency in tests,
    // so no mock is needed here — mirrors the pattern in the existing valid-platform cases.
    IntegrationConnection::updateOrCreate(
        ['user_id' => $user->id, 'platform' => 'instagram'],
        ['resource_id' => 'ig-no-report', 'payload' => []]
    );

    Exceptions::assertNotReported(UnregisteredPlatformException::class);
});

// SEC-1: tenant-anchor immutability guard — user_id must never change once a row
// is persisted. The guard fires before the platform guard so it catches changes
// even on rows where platform is unchanged.

it('throws TenantAnchorImmutableException when user_id is changed on a persisted row', function () {
    $owner = makeGuardTestUser('anchor-user-1');
    $intruder = makeGuardTestUser('anchor-intruder-1');

    // Create a valid connection owned by $owner — bind the scraper mock first
    // because the saving guard resolves PlatformRegistry on first save.
    $conn = IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'instagram',
        'resource_id' => 'ig-sec1-a',
        'payload' => [],
    ]);

    // Attempt to re-assign the tenant anchor on the already-persisted row.
    expect(fn () => $conn->update(['user_id' => $intruder->id]))
        ->toThrow(TenantAnchorImmutableException::class);
});

it('does not throw when create() assigns user_id on a new row', function () {
    $user = makeGuardTestUser('anchor-user-2');

    // user_id is in $fillable; setting it on create is the normal path — must not throw.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'ig-sec1-b',
        'payload' => [],
    ]);

    expect($conn->exists)->toBeTrue();
    expect($conn->user_id)->toBe($user->id);
});

it('does not throw when updateOrCreate() creates a new row with user_id', function () {
    $user = makeGuardTestUser('anchor-user-3');

    // The upsert idiom — user_id is a lookup key on the create path; must not throw.
    $conn = IntegrationConnection::updateOrCreate(
        ['user_id' => $user->id, 'platform' => 'google-business'],
        ['resource_id' => 'gb-sec1-c', 'payload' => ['name' => 'Test']]
    );

    expect($conn->exists)->toBeTrue();
    expect($conn->user_id)->toBe($user->id);
});

it('does not throw when a non-anchor field is updated on a persisted row', function () {
    $user = makeGuardTestUser('anchor-user-4');

    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'ig-sec1-d',
        'payload' => [],
    ]);

    // Status-only update — neither user_id nor platform is dirty; neither guard fires.
    $conn->update(['last_refresh_status' => 'ok', 'payload' => ['items' => []]]);

    expect($conn->fresh()->last_refresh_status)->toBe('ok');
});
