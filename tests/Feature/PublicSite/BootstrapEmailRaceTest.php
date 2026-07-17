<?php

/**
 * LIFE-6 + 07-08 #LIFE-12 (user-deletion-lifecycle audit) + LIFE-101 (full-work-sweep
 * 2026-07-18): all three findings live in UserBootstrapService::bootstrap().
 *
 * LIFE-6 is the TOCTOU race between the email-reuse pre-check and the save() that
 * follows it — the DB's case-insensitive unique index (lower(primary_email)) is the
 * real backstop, and a violation must surface as the same friendly
 * EMAIL_ALREADY_REGISTERED the pre-check throws, not a raw 500.
 *
 * LIFE-12 is covered by the second test here: the locked re-fetch added inside the
 * transaction must not break the ordinary create path.
 *
 * LIFE-101: the original LIFE-6 fix classified the race by str_contains()-matching the
 * driver's error message ('users_email_unique' / 'primary_email') — fragile across
 * driver/version/locale, and effectively untested (SQLite's message differs from
 * Postgres's). save() is now wrapped in its own nested transaction (SAVEPOINT), so a
 * 23505 no longer poisons the outer signup transaction and the catch can re-query the
 * DB to classify the cause instead of guessing from text. The re-query is exercised by
 * the SQLite tests below (classifier precision + common case, both driver-agnostic);
 * the genuinely-concurrent race itself can only be reproduced against real Postgres
 * with a second connection — see the Postgres-gated tests at the bottom, modeled on
 * tests/Feature/Bootstrap/SiteProvisioningSavepointTest.php.
 */

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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

it('throws EMAIL_ALREADY_REGISTERED via the pre-check when the email is already taken by a different auth user (common case)', function () {
    // A pre-existing (already-committed) rival — no race involved. This is the
    // ordinary path: guardAgainstEmailReuseByDifferentAuthUser() finds the row and
    // throws BEFORE save() ever runs, so the LIFE-101 nested-tx catch never fires.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'rival-uid',
        'handle' => 'existingrival',
        'handle_lc' => 'existingrival',
        'display_name' => 'Existing Rival',
        'primary_email' => 'taken@example.com',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    expect(fn () => app(UserBootstrapService::class)->bootstrap('newcomer-uid', raceBootstrapPayload([
        'handle' => 'newcomer',
        'handle_lc' => 'newcomer',
        'display_name' => 'Newcomer',
        'primary_email' => 'TAKEN@Example.com', // different case, same lower()
        'first_name' => 'Newcomer',
    ])))->toThrow(RuntimeException::class, 'EMAIL_ALREADY_REGISTERED');

    expect(User::query()->where('auth_user_id', 'newcomer-uid')->exists())->toBeFalse();
});

it('LIFE-101: re-throws the raw UniqueConstraintViolationException (does not mis-classify) when the collision is a non-email unique violation', function () {
    // Real unique indexes on the SQLite stub, mirroring the constraints defined in
    // supabase/migrations/20260526000000_baseline_standalone_user.sql
    // (core_users_handle_lc_unique) and 20260711170000_users_email_unique_case_insensitive.sql
    // (users_email_unique, now case-insensitive on lower(primary_email)).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS core.users_email_unique '.
        'ON users (lower(primary_email)) WHERE deleted_at IS NULL'
    );
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS core.core_users_handle_lc_unique '.
        'ON users (handle_lc) WHERE deleted_at IS NULL'
    );

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'other-uid',
        'handle' => 'taken',
        'handle_lc' => 'taken',
        'display_name' => 'Handle Holder',
        'primary_email' => 'other@example.com',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Different email — the pre-check and the post-catch re-query both look ONLY at
    // primary_email, so a handle_lc collision must surface as the raw
    // UniqueConstraintViolationException, never as EMAIL_ALREADY_REGISTERED.
    expect(fn () => app(UserBootstrapService::class)->bootstrap('new-uid', raceBootstrapPayload([
        'handle' => 'taken',
        'handle_lc' => 'taken',
        'display_name' => 'New Arrival',
        'primary_email' => 'new@example.com',
        'first_name' => 'New',
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(User::query()->where('auth_user_id', 'new-uid')->exists())->toBeFalse();
});

// ─── Postgres-gated: the genuine concurrent race ──────────────────────────────────
//
// The pre-fix same-connection `User::saving` hook simulated concurrency by inserting
// the rival row mid-save on the SAME connection. That trick no longer proves anything
// under the LIFE-101 fix: the rival insert now happens INSIDE the nested transaction
// wrapped around $professional->save(), so when the savepoint rolls back on the 23505,
// it ALSO undoes the rival insert — the post-catch re-query then finds nothing and
// re-throws the raw UniqueConstraintViolationException instead of the friendly error.
//
// A real concurrent signup is a genuinely separate Postgres session that COMMITS
// independently and durably — not undone by our savepoint rollback. That can only be
// reproduced against real PostgreSQL with a second connection, following the same
// gate + pattern as tests/Feature/Bootstrap/SiteProvisioningSavepointTest.php.

if (! function_exists('emailRaceSuiteIsPostgres')) {
    function emailRaceSuiteIsPostgres(): bool
    {
        return DB::connection('pgsql')->getDriverName() === 'pgsql';
    }
}

it('LIFE-101 mechanism: after a nested-tx 23505 rolls back to its savepoint, a same-connection re-query still runs and sees a row committed by a second connection (Postgres only)', function () {
    if (! emailRaceSuiteIsPostgres()) {
        $this->markTestSkipped('PostgreSQL transaction-abort + savepoint semantics required; SQLite cannot reproduce a genuinely concurrent commit.');
    }

    $table = 'email_race_probe_'.Str::lower(Str::random(8));
    DB::connection('pgsql')->statement("CREATE TABLE {$table} (val text UNIQUE)");

    // A second, INDEPENDENT connection — a real separate Postgres session, standing
    // in for a concurrent signup request.
    config(['database.connections.pgsql_race_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_race_rival');

    try {
        $result = DB::connection('pgsql')->transaction(function () use ($table, $rival) {
            // Outer transaction == the signup transaction in UserBootstrapService::bootstrap().

            // The second session commits its row WHILE our outer transaction is open —
            // this is the real TOCTOU window: our pre-check already ran (and passed)
            // before this point. Autocommits immediately ($rival has no open tx of
            // its own).
            $rival->table($table)->insert(['val' => 'taken']);

            // Our own insert collides — wrapped in a nested transaction exactly as
            // UserBootstrapService now wraps $professional->save().
            $caught = false;
            try {
                DB::connection('pgsql')->transaction(function () use ($table) {
                    DB::connection('pgsql')->table($table)->insert(['val' => 'taken']); // → 23505
                });
            } catch (QueryException $e) {
                $caught = $e->getCode() === '23505';
            }

            // The re-query: pre-fix (flat catch, no savepoint) this SELECT would throw
            // 25P02 because the outer transaction was poisoned. Post-fix, the savepoint
            // rollback kept the outer tx clean, and this SELECT correctly sees the row
            // the second connection committed.
            $seesRival = DB::connection('pgsql')->table($table)->where('val', 'taken')->exists();

            return ['caught' => $caught, 'seesRival' => $seesRival];
        });

        expect($result['caught'])->toBeTrue('Expected the duplicate insert to raise a 23505 unique violation.');
        expect($result['seesRival'])->toBeTrue('Re-query must see the row a second connection committed, not throw 25P02.');
    } finally {
        DB::connection('pgsql')->statement("DROP TABLE IF EXISTS {$table}");
        DB::disconnect('pgsql_race_rival');
    }
});

it('LIFE-101: throws EMAIL_ALREADY_REGISTERED (via re-query, not driver-message matching) when a second real Postgres connection commits the rival email during the nested-tx save (Postgres only)', function () {
    if (! emailRaceSuiteIsPostgres()) {
        $this->markTestSkipped('PostgreSQL transaction-abort + savepoint semantics required; SQLite cannot reproduce a genuinely concurrent commit from a second session.');
    }

    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS core.users_email_unique '.
        'ON users (lower(primary_email)) WHERE deleted_at IS NULL'
    );

    $email = 'race-'.Str::lower(Str::random(8)).'@example.com';
    $targetAuthId = 'race-target-'.Str::lower(Str::random(8));
    $rivalAuthId = 'race-rival-'.Str::lower(Str::random(8));
    $rivalHandle = 'racerival'.Str::lower(Str::random(8));
    $handle = 'race'.Str::lower(Str::random(8));

    // A genuinely separate Postgres session — models a truly concurrent signup
    // request. Unlike the old same-connection `saving` hook, this commits
    // independently and durably, so a savepoint rollback on OUR connection cannot
    // undo it — exactly like two real HTTP requests racing each other.
    config(['database.connections.pgsql_race_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_race_rival');

    // One-shot hook: the instant the target user's row is about to be saved (i.e.
    // strictly AFTER guardAgainstEmailReuseByDifferentAuthUser's pre-check already ran
    // and passed), the SECOND connection claims + commits the same email under a
    // different case, opening the TOCTOU window the pre-check cannot see.
    $fired = false;
    User::saving(function (User $u) use (&$fired, $email, $rivalAuthId, $rivalHandle, $rival) {
        if ($fired || $u->primary_email !== $email) {
            return;
        }
        $fired = true;
        $rival->table('core.users')->insert([
            'id' => (string) Str::uuid(),
            'auth_user_id' => $rivalAuthId,
            'handle' => $rivalHandle,
            'handle_lc' => $rivalHandle,
            'display_name' => 'Race Rival',
            'primary_email' => strtoupper($email), // different case, same lower()
            'account_type' => 'partna',
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    });

    try {
        expect(fn () => app(UserBootstrapService::class)->bootstrap($targetAuthId, raceBootstrapPayload([
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'Racer',
            'primary_email' => $email,
            'first_name' => 'Racer',
        ])))->toThrow(RuntimeException::class, 'EMAIL_ALREADY_REGISTERED');

        // Falsifiability, as in the original test: no row could have existed for
        // guardAgainstEmailReuseByDifferentAuthUser to catch when it ran — the rival is
        // committed by the hook DURING save(), strictly after the pre-check already
        // passed. The exception can only have come from the save()-time catch's
        // re-query.
        expect(User::query()->where('auth_user_id', $targetAuthId)->exists())->toBeFalse();
    } finally {
        DB::connection('pgsql')->table('core.users')->whereIn('auth_user_id', [$targetAuthId, $rivalAuthId])->delete();
        DB::disconnect('pgsql_race_rival');
    }
});
