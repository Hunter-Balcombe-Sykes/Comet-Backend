<?php

// Business accounts don't offer the lifestyle pages (Listen / Strava / Skool)
// and their dashboard hides those groups, so a lifestyle connection
// carried over from when the account was `partna` is an un-removable orphan.
// LifestyleConnectionCleanup soft-deletes them; the UserObserver runs it on the
// partna→business switch, and the artisan command mops up existing orphans.

use App\Catalog\LegacyPlatformMap;
use App\Enums\AccountType;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Accounts\LifestyleConnectionCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    AccountCapabilities::flushCache();
});

function lccConnection(string $userId, string $platform): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => LegacyPlatformMap::surfaceFor($platform),
        'routing_class' => LegacyPlatformMap::routingClassFor(LegacyPlatformMap::surfaceFor($platform)),
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode(['x' => 1]),
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function lccTenant(string $handle, string $accountType): User
{
    $pro = createTenant($handle);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update(['account_type' => $accountType]);
    AccountCapabilities::flushCache();

    return User::query()->findOrFail($pro->id);
}

function lccActiveCount(string $userId): int
{
    return IntegrationConnection::query()->where('user_id', $userId)->count(); // SoftDeletes scope excludes deleted
}

it('derives the lifestyle platform set from the presence gate', function () {
    $platforms = LifestyleConnectionCleanup::lifestylePlatforms();
    // Listen + community + other platforms, NOT shop/bandcamp/google-business/booking.
    expect($platforms)->toContain('apple-music', 'spotify', 'soundcloud', 'strava', 'skool')
        ->and($platforms)->not->toContain('shop', 'bandcamp', 'fresha', 'google-business', 'instagram');
});

it('soft-deletes a business account lifestyle connections but leaves shop/other', function () {
    $pro = lccTenant('lcc-biz', 'business');
    lccConnection($pro->id, 'apple-music');
    lccConnection($pro->id, 'strava');
    lccConnection($pro->id, 'shop');            // business keeps shop
    lccConnection($pro->id, 'google-business'); // not lifestyle

    $removed = app(LifestyleConnectionCleanup::class)->forUser($pro);

    expect($removed)->toBe(2)
        ->and(lccActiveCount($pro->id))->toBe(2); // shop + google-business remain
});

it('is a no-op for standard (partna) accounts', function () {
    $pro = lccTenant('lcc-partna', 'partna');
    lccConnection($pro->id, 'apple-music');
    lccConnection($pro->id, 'strava');

    $removed = app(LifestyleConnectionCleanup::class)->forUser($pro);

    expect($removed)->toBe(0)
        ->and(lccActiveCount($pro->id))->toBe(2);
});

it('dry-run counts without deleting', function () {
    $pro = lccTenant('lcc-dry', 'business');
    lccConnection($pro->id, 'apple-music');
    lccConnection($pro->id, 'strava');

    $would = app(LifestyleConnectionCleanup::class)->forUser($pro, dryRun: true);

    expect($would)->toBe(2)
        ->and(lccActiveCount($pro->id))->toBe(2); // untouched
});

it('cleans up lifestyle connections when a user switches to business (observer)', function () {
    $pro = lccTenant('lcc-switch', 'partna');
    lccConnection($pro->id, 'apple-music');
    lccConnection($pro->id, 'skool');
    lccConnection($pro->id, 'shop');
    expect(lccActiveCount($pro->id))->toBe(3);

    // The switch fires UserObserver::updated → cleanup.
    $pro->update(['account_type' => AccountType::Business->value]);
    AccountCapabilities::flushCache();

    expect(lccActiveCount($pro->id))->toBe(1); // only shop survives
});

it('leaves lifestyle connections untouched when an unrelated field changes on a business account (TEST-107)', function () {
    $pro = lccTenant('lcc-unrelated', 'business');
    lccConnection($pro->id, 'apple-music');
    lccConnection($pro->id, 'strava');
    expect(lccActiveCount($pro->id))->toBe(2);

    // account_type is unchanged (still 'business') — UserObserver's guard
    // (wasChanged('account_type')) must NOT fire the cleanup for any other
    // field edit, or every profile save on a business account would silently
    // wipe lifestyle connections.
    $pro->update(['display_name' => 'New Display Name']);

    expect(lccActiveCount($pro->id))->toBe(2);
});

it('the artisan command removes orphans across business accounts (and dry-run does not)', function () {
    $biz = lccTenant('lcc-cmd-biz', 'business');
    lccConnection($biz->id, 'spotify');
    lccConnection($biz->id, 'strava');
    $partna = lccTenant('lcc-cmd-partna', 'partna');
    lccConnection($partna->id, 'spotify');

    // Dry-run touches nothing.
    $this->artisan('connections:cleanup-lifestyle-orphans', ['--dry-run' => true])->assertOk();
    expect(lccActiveCount($biz->id))->toBe(2)
        ->and(lccActiveCount($partna->id))->toBe(1);

    // Real run clears the business orphans, leaves the partna account alone.
    $this->artisan('connections:cleanup-lifestyle-orphans')->assertOk();
    expect(lccActiveCount($biz->id))->toBe(0)
        ->and(lccActiveCount($partna->id))->toBe(1);
});
