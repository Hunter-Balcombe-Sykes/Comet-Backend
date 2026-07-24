<?php

// CACHE-1: UserCacheService::invalidateUser() built CacheKeyGenerator::
// professionalPayloadByAuthId()/userIdByAuthId() from $professional->auth_user_id
// unconditionally. Both generators take a non-nullable `string`, so an unclaimed
// pre-account user (auth_user_id IS NULL — see
// supabase/migrations/20260718200000_pre_account_sites.sql, "provisional users
// have no auth user") triggered a TypeError while the `$keys` array literal was
// still being *evaluated* — the throw escaped BEFORE Cache::deleteMultiple() ran
// AND before the site-bust catch-all at the end of the method. Callers
// (UserObserver) catch \Throwable and report() it, so this failed OPEN: a
// hard-deleted unclaimed user's cached payload survived to TTL instead of being
// forgotten. Nightwatch issue #276 (development, 6 hits/24h, 17/30d).
//
// These tests assert the actual OUTCOME (keys gone from cache), not just "no
// exception" — a test that only checked for a missing exception would have
// passed before this bug existed too, since the crash was silently swallowed.

use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Cache::flush();
});

/**
 * Seed every non-auth-keyed cache entry invalidateUser() is supposed to forget,
 * keyed by a human label for readable assertion failures.
 *
 * @return array<string, string>
 */
function seedUserCacheKeys(User $user): array
{
    $handleLc = strtolower($user->handle);

    $keys = [
        'payload_by_id' => CacheKeyGenerator::professionalPayloadById($user->id),
        'payload_by_handle' => CacheKeyGenerator::professionalPayloadByHandle($handleLc),
        'id_by_handle' => CacheKeyGenerator::userIdByHandle($handleLc),
        'model' => CacheKeyGenerator::professionalModel($user->id),
        'model_stale' => CacheKeyGenerator::staleKey(CacheKeyGenerator::professionalModel($user->id)),
        'services' => CacheKeyGenerator::professionalServices($user->id),
        'services_stale' => CacheKeyGenerator::staleKey(CacheKeyGenerator::professionalServices($user->id)),
        'dashboard_services' => CacheKeyGenerator::professionalDashboardServices($user->id),
        'dashboard_services_stale' => CacheKeyGenerator::staleKey(CacheKeyGenerator::professionalDashboardServices($user->id)),
        'customer_count' => CacheKeyGenerator::customerCount($user->id),
        'customer_count_stale' => CacheKeyGenerator::staleKey(CacheKeyGenerator::customerCount($user->id)),
    ];

    foreach ($keys as $key) {
        Cache::put($key, 'seeded', now()->addMinutes(15));
    }

    return $keys;
}

it('invalidates every non-auth cache key when an UNCLAIMED user (auth_user_id NULL) is hard-deleted', function () {
    Queue::fake();
    Exceptions::fake();

    // Real provisional row: no auth_user_id, no primary_email — mirrors
    // GeneratePreAccountSiteJob's output and tests/Feature/PreAccount/
    // UnclaimedGatingTest.php's makeUnclaimedWithSite() fixture pattern.
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);

    $keys = seedUserCacheKeys($user);

    // Hard-delete via the real observer path — mirrors PruneExpiredPreAccountBuilds'
    // $user->forceDelete(), which is exactly where Nightwatch #276 fired.
    $user->forceDelete();

    foreach ($keys as $label => $key) {
        expect(Cache::has($key))->toBeFalse("expected cache key [{$label}] ({$key}) to have been invalidated");
    }

    // Secondary: confirm the fix didn't just relocate the TypeError somewhere
    // else report() would still catch it.
    Exceptions::assertNothingReported();
});

it('still invalidates the auth-keyed cache entries for a CLAIMED user (auth_user_id present) — no regression', function () {
    Queue::fake();
    Exceptions::fake();

    // Factory default sets a real auth_user_id (claimed user).
    $user = User::factory()->create();

    $authKeys = [
        'payload_by_auth' => CacheKeyGenerator::professionalPayloadByAuthId($user->auth_user_id),
        'id_by_auth' => CacheKeyGenerator::userIdByAuthId($user->auth_user_id),
    ];
    foreach ($authKeys as $key) {
        Cache::put($key, 'seeded', now()->addMinutes(15));
    }

    $nonAuthKeys = seedUserCacheKeys($user);

    $user->forceDelete();

    foreach ([...$authKeys, ...$nonAuthKeys] as $label => $key) {
        expect(Cache::has($key))->toBeFalse("expected cache key [{$label}] ({$key}) to have been invalidated");
    }

    Exceptions::assertNothingReported();
});
