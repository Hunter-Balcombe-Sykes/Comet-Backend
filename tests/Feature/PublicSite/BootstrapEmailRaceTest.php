<?php

/**
 * LIFE-6 + 07-08 #LIFE-12 (user-deletion-lifecycle audit): both findings live in
 * UserBootstrapService::bootstrap(). LIFE-6 is the TOCTOU race between the
 * email-reuse pre-check and the save() that follows it — the DB's
 * case-insensitive unique index (lower(primary_email)) is the real backstop,
 * and a violation must surface as the same friendly EMAIL_ALREADY_REGISTERED
 * the pre-check throws, not a raw 500. LIFE-12 is covered by the second test
 * here: the locked re-fetch added inside the transaction must not break the
 * ordinary create path.
 */

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupEmailSubscriptionsTable();
    setupNotificationsTable(); // createWelcomeNotification() writes here on a brand-new signup
    setupSubdomainAliasesTable(); // SiteCacheService::invalidateSite (via cache->invalidateUser) reads this
    Queue::fake(); // avoid the KV-sync / cache-purge / cache-warm jobs actually running under QUEUE_CONNECTION=sync
});

/**
 * Minimal valid bootstrap payload for a brand-new user.
 *
 * @return array<string, mixed>
 */
function raceBootstrapPayload(array $overrides = []): array
{
    return array_merge([
        'handle' => 'racer',
        'handle_lc' => 'racer',
        'display_name' => 'Racer',
        'primary_email' => 'racer@example.com',
        'first_name' => 'Racer',
        'account_type' => 'partna',
    ], $overrides);
}

it('throws EMAIL_ALREADY_REGISTERED (not a raw UniqueConstraintViolationException) when a concurrent signup wins the primary_email TOCTOU race (LIFE-6)', function () {
    $targetEmail = 'racer@example.com';

    // Real case-insensitive unique expression index — mirrors the users_email_unique
    // index this migration replaces, so a duplicate lower(primary_email) genuinely
    // throws UniqueConstraintViolationException on the sqlite test driver (proven
    // separately: the raw SQLite message is "UNIQUE constraint failed: index
    // 'users_email_unique'", which is what the catch's str_contains() matches on).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS core.users_email_unique '.
        'ON users (lower(primary_email)) WHERE deleted_at IS NULL'
    );

    // One-shot hook: the instant the target user's row is about to be saved (i.e.
    // strictly AFTER guardAgainstEmailReuseByDifferentAuthUser's pre-check already
    // ran and passed), a rival claims the same email — different case, same
    // lower(primary_email) — opening the TOCTOU window the pre-check cannot see.
    // Direct DB insert bypasses model events, so this cannot recurse.
    $fired = false;
    User::saving(function (User $u) use (&$fired, $targetEmail) {
        if (! $fired && $u->primary_email === $targetEmail) {
            $fired = true;
            DB::connection('pgsql')->table('core.users')->insert([
                'id' => (string) Str::uuid(),
                'auth_user_id' => 'rival-uid',
                'handle' => 'racerrival',
                'handle_lc' => 'racerrival',
                'display_name' => 'Racer Rival',
                'primary_email' => 'RACER@Example.com',
                'account_type' => 'partna',
                'status' => 'active',
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
    });

    expect(fn () => app(UserBootstrapService::class)->bootstrap('target-uid', raceBootstrapPayload()))
        ->toThrow(RuntimeException::class, 'EMAIL_ALREADY_REGISTERED');

    // Falsifiability: no row can have existed for guardAgainstEmailReuseByDifferentAuthUser
    // to catch when it ran — the conflicting row is inserted by the hook DURING save(),
    // strictly after the pre-check already passed. The exception can only have
    // originated from the save()-time catch, never from the pre-check.
    expect(User::query()->where('auth_user_id', 'target-uid')->exists())->toBeFalse();
});

it('still completes a brand-new signup end-to-end after the locked re-fetch was added inside the transaction (07-08 #LIFE-12 regression guard)', function () {
    $result = app(UserBootstrapService::class)->bootstrap('fresh-uid', raceBootstrapPayload([
        'handle' => 'freshuser',
        'handle_lc' => 'freshuser',
        'display_name' => 'Fresh User',
        'primary_email' => 'freshuser@example.com',
        'first_name' => 'Fresh',
    ]));

    expect($result['created'])->toBeTrue();
    expect($result['professional'])->toBeInstanceOf(User::class);
    expect($result['site'])->toBeInstanceOf(Site::class);

    $row = User::query()->where('auth_user_id', 'fresh-uid')->first();
    expect($row)->not->toBeNull();
    expect($row->primary_email)->toBe('freshuser@example.com');
    expect(Site::query()->where('user_id', $row->id)->exists())->toBeTrue();
});
