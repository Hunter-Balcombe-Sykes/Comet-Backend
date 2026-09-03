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
 * tests/Schema/SiteProvisioningSavepointTest.php.
 *
 * MOVED to the applied-schema lane (tranche 3 of COV-LANE). Two genuine bugs in the
 * SQLite-era version of this file, neither an app defect:
 *
 * 1. Every auth-user placeholder id in this file ('fresh-uid', 'rival-uid',
 *    'newcomer-uid', 'other-uid', 'new-uid', 'race-target-...', 'race-rival-...') was a
 *    non-UUID string. SQLite's TEXT-typed stand-in column swallows anything; real
 *    Postgres's core.users.auth_user_id is a genuine `uuid` column and rejects them
 *    with `invalid input syntax for type uuid`. Every id below is now a real UUID, and
 *    — because auth_user_id is ALSO a real FK onto auth.users (see
 *    Tests\Schema\Concerns\SeedsAuthUsers) — each one gets a matching auth.users row
 *    before use.
 *
 * 2. The DISC-6-style tests fabricated core.users_email_unique /
 *    core_users_handle_lc_unique inside SQLite's in-memory schema with
 *    `CREATE UNIQUE INDEX core.name ON users (...)` — SQLite-only syntax (the schema
 *    qualifier belongs on the TABLE, not the index; real Postgres parses it as
 *    `syntax error at or near "."`, the same bug class fixed in
 *    DesignSingletonMediaConcurrencyTest). Both indexes already exist for real in the
 *    applied schema (20260726000000_baseline_pilot.sql,
 *    20260711170000_users_email_unique_case_insensitive.sql), so the fabrication
 *    statements are simply removed — the tests now exercise the REAL constraints.
 *
 * Directory-scope warning: tests/Pest.php:2918-2924 binds a fake bot-protection
 * provider via `uses()->beforeEach(...)->in('Feature/Http/Middleware',
 * 'Feature/PublicSite', 'Feature/Security')`. This file used to live in
 * Feature/PublicSite and inherited that binding; moved out, it's gone with no error
 * pointing at the cause. Nothing in UserBootstrapService's call graph currently
 * touches bot protection, so its absence wouldn't have failed a test today — but the
 * binding is re-established explicitly below anyway, matching the original directory
 * behaviour instead of silently relying on that being permanently true.
 */

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\User\UserBootstrapService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

beforeEach(function () {
    Queue::fake(); // avoid the KV-sync / cache-purge / cache-warm jobs actually running under QUEUE_CONNECTION=sync

    // Re-establish the directory-scoped fake this file inherited under
    // Feature/PublicSite (see header comment) — cheap insurance even though
    // nothing here currently resolves it.
    config(['partna.bot_protection.driver' => 'fake']);
    app()->instance(FakeProvider::class, new FakeProvider);
});

/** Insert just the auth.users parent row and return its id — for ids that are
 * handed to UserBootstrapService::bootstrap() directly rather than through
 * SeedsAuthUsers::seedAuthUser() (which also creates the core.users row via
 * the factory; bootstrap() creates that row itself). */
function seedRaceAuthId(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('auth.users')->insert(['id' => $id, 'email' => $id.'@schema-lane.test']);

    return $id;
}

/** Undo seedRaceAuthId() when bootstrap() never got far enough to create the
 * core.users row (e.g. it threw before save()). */
function cleanupRaceAuthId(string $authId): void
{
    DB::connection('pgsql')->table('auth.users')->where('id', $authId)->delete();
}

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
    $authId = seedRaceAuthId();
    $handle = 'freshuser'.Str::lower(Str::random(6));

    $result = null;

    try {
        $result = app(UserBootstrapService::class)->bootstrap($authId, raceBootstrapPayload([
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'Fresh User',
            'primary_email' => $handle.'@example.com',
            'first_name' => 'Fresh',
        ]));

        expect($result['created'])->toBeTrue();
        expect($result['professional'])->toBeInstanceOf(User::class);
        expect($result['site'])->toBeInstanceOf(Site::class);

        $row = User::query()->where('auth_user_id', $authId)->first();
        expect($row)->not->toBeNull();
        expect($row->primary_email)->toBe($handle.'@example.com');
        expect(Site::query()->where('user_id', $row->id)->exists())->toBeTrue();
    } finally {
        if ($result) {
            $this->cleanupSeededUser($result['professional']);
        } else {
            cleanupRaceAuthId($authId);
        }
    }
});

it('throws EMAIL_ALREADY_REGISTERED when the email is held by an UNBOUND row (auth_user_id null)', function () {
    // 2026-09-03: deleting a Supabase auth user out from under a claimed row
    // nulls core.users.auth_user_id via the FK but leaves status='active' and
    // primary_email intact. The guard's `auth_user_id != $uid` could never
    // match that row — `NULL != 'uid'` is NULL in SQL, not true — so the email
    // read as free, the save hit users_email_unique, and the 23505 re-check
    // failed identically: the claim rethrew and sign-up's Finish answered a
    // bare 500 "An error occurred".
    $orphanHandle = 'orphanrow'.Str::lower(Str::random(6));
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => null, // the auth user is gone; the address is not
        'handle' => $orphanHandle,
        'handle_lc' => $orphanHandle,
        'display_name' => 'Orphan Row',
        'first_name' => 'Orphan',
        'primary_email' => 'stranded@example.com',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $newcomerAuthId = seedRaceAuthId();
    $newcomerHandle = 'newcomer'.Str::lower(Str::random(6));

    try {
        expect(fn () => app(UserBootstrapService::class)->bootstrap($newcomerAuthId, raceBootstrapPayload([
            'handle' => $newcomerHandle,
            'handle_lc' => $newcomerHandle,
            'display_name' => 'Newcomer',
            'primary_email' => 'Stranded@Example.com', // different case, same lower()
            'first_name' => 'Newcomer',
        ])))->toThrow(RuntimeException::class, 'EMAIL_ALREADY_REGISTERED');

        expect(User::query()->where('auth_user_id', $newcomerAuthId)->exists())->toBeFalse();
    } finally {
        User::query()->where('handle', $orphanHandle)->forceDelete();
        cleanupRaceAuthId($newcomerAuthId);
    }
});

it('throws EMAIL_ALREADY_REGISTERED via the pre-check when the email is already taken by a different auth user (common case)', function () {
    // A pre-existing (already-committed) rival — no race involved. This is the
    // ordinary path: guardAgainstEmailReuseByDifferentAuthUser() finds the row and
    // throws BEFORE save() ever runs, so the LIFE-101 nested-tx catch never fires.
    $rivalAuthId = seedRaceAuthId();
    $rivalHandle = 'existingrival'.Str::lower(Str::random(6));
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $rivalAuthId,
        'handle' => $rivalHandle,
        'handle_lc' => $rivalHandle,
        'display_name' => 'Existing Rival',
        'first_name' => 'Existingrival',
        'primary_email' => 'taken@example.com',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $newcomerAuthId = seedRaceAuthId();
    $newcomerHandle = 'newcomer'.Str::lower(Str::random(6));

    try {
        expect(fn () => app(UserBootstrapService::class)->bootstrap($newcomerAuthId, raceBootstrapPayload([
            'handle' => $newcomerHandle,
            'handle_lc' => $newcomerHandle,
            'display_name' => 'Newcomer',
            'primary_email' => 'TAKEN@Example.com', // different case, same lower()
            'first_name' => 'Newcomer',
        ])))->toThrow(RuntimeException::class, 'EMAIL_ALREADY_REGISTERED');

        expect(User::query()->where('auth_user_id', $newcomerAuthId)->exists())->toBeFalse();
    } finally {
        $rival = User::query()->where('auth_user_id', $rivalAuthId)->first();
        if ($rival) {
            $this->cleanupSeededUser($rival);
        } else {
            cleanupRaceAuthId($rivalAuthId);
        }
        cleanupRaceAuthId($newcomerAuthId); // never got a core.users row
    }
});

it('DISC-6: classifies a concurrent handle_lc collision as HANDLE_ALREADY_TAKEN (re-query, not driver-message), never as email or a raw 500', function () {
    // Real unique indexes already exist on the applied schema — see
    // supabase/migrations/20260726000000_baseline_pilot.sql (core_users_handle_lc_unique)
    // and 20260711170000_users_email_unique_case_insensitive.sql (users_email_unique,
    // case-insensitive on lower(primary_email)). No fabrication needed here.
    $handle = 'taken'.Str::lower(Str::random(6));
    $otherAuthId = seedRaceAuthId();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $otherAuthId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Handle Holder',
        'first_name' => 'Taken',
        'primary_email' => 'other'.Str::lower(Str::random(6)).'@example.com',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $newAuthId = seedRaceAuthId();

    try {
        // Different email — the pre-check and the post-catch email re-query both look
        // ONLY at primary_email, so a handle_lc collision must NOT surface as
        // EMAIL_ALREADY_REGISTERED. DISC-6: it is now deliberately classified via a
        // second re-query (never driver-message string matching) as the friendly
        // HANDLE_ALREADY_TAKEN, not left as a raw UniqueConstraintViolationException
        // (which the controller can't translate and turns into a 500).
        expect(fn () => app(UserBootstrapService::class)->bootstrap($newAuthId, raceBootstrapPayload([
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'New Arrival',
            'primary_email' => 'new'.Str::lower(Str::random(6)).'@example.com',
            'first_name' => 'New',
        ])))->toThrow(RuntimeException::class, 'HANDLE_ALREADY_TAKEN');

        expect(User::query()->where('auth_user_id', $newAuthId)->exists())->toBeFalse();
    } finally {
        $other = User::query()->where('auth_user_id', $otherAuthId)->first();
        if ($other) {
            $this->cleanupSeededUser($other);
        } else {
            cleanupRaceAuthId($otherAuthId);
        }
        cleanupRaceAuthId($newAuthId); // never got a core.users row
    }
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
// independently and durably — not undone by our savepoint rollback. That requires a
// real, migrated Postgres with a second connection, following the same gate + pattern
// as tests/Schema/SiteProvisioningSavepointTest.php (SchemaTestCase's setUp() already
// enforces that for the whole file).

it('LIFE-101 mechanism: after a nested-tx 23505 rolls back to its savepoint, a same-connection re-query still runs and sees a row committed by a second connection', function () {
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

it('LIFE-101: throws EMAIL_ALREADY_REGISTERED (via re-query, not driver-message matching) when a second real Postgres connection commits the rival email during the nested-tx save', function () {
    $email = 'race-'.Str::lower(Str::random(8)).'@example.com';
    $targetAuthId = seedRaceAuthId();
    $rivalAuthId = seedRaceAuthId();
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
            'first_name' => ucfirst($rivalHandle),
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
        DB::connection('pgsql')->table('auth.users')->whereIn('id', [$targetAuthId, $rivalAuthId])->delete();
        DB::disconnect('pgsql_race_rival');
    }
});

it('DISC-6: throws HANDLE_ALREADY_TAKEN (via re-query, not driver-message matching) when a second real Postgres connection commits the rival handle_lc during the nested-tx save', function () {
    // Real unique index already exists on the applied schema
    // (core_users_handle_lc_unique) — no fabrication needed here.
    $handle = 'race'.Str::lower(Str::random(8));
    $targetAuthId = seedRaceAuthId();
    $rivalAuthId = seedRaceAuthId();
    $rivalEmail = 'race-rival-'.Str::lower(Str::random(8)).'@example.com';
    $targetEmail = 'race-'.Str::lower(Str::random(8)).'@example.com';

    // A genuinely separate Postgres session — models a truly concurrent signup
    // request. Unlike a same-connection `saving` hook, this commits independently
    // and durably, so a savepoint rollback on OUR connection cannot undo it —
    // exactly like two real HTTP requests racing each other.
    config(['database.connections.pgsql_race_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_race_rival');

    // One-shot hook: the instant the target user's row is about to be saved (i.e.
    // strictly AFTER BootstrapRequest's Rule::unique(handle_lc) would already have
    // passed on a real request), the SECOND connection claims + commits the same
    // handle_lc, opening the TOCTOU window the pre-save validation cannot see.
    $fired = false;
    User::saving(function (User $u) use (&$fired, $handle, $rivalAuthId, $rivalEmail, $rival) {
        if ($fired || $u->handle_lc !== $handle) {
            return;
        }
        $fired = true;
        $rival->table('core.users')->insert([
            'id' => (string) Str::uuid(),
            'auth_user_id' => $rivalAuthId,
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'Race Rival',
            'first_name' => ucfirst($handle),
            'primary_email' => $rivalEmail,
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
            'primary_email' => $targetEmail,
            'first_name' => 'Racer',
        ])))->toThrow(RuntimeException::class, 'HANDLE_ALREADY_TAKEN');

        // Falsifiability, as in the email sibling: no row could have existed for a
        // pre-save check to catch when it ran — the rival is committed by the hook
        // DURING save(), strictly after any pre-check would have passed. The
        // exception can only have come from the save()-time catch's re-query.
        expect(User::query()->where('auth_user_id', $targetAuthId)->exists())->toBeFalse();
    } finally {
        DB::connection('pgsql')->table('core.users')->whereIn('auth_user_id', [$targetAuthId, $rivalAuthId])->delete();
        DB::connection('pgsql')->table('auth.users')->whereIn('id', [$targetAuthId, $rivalAuthId])->delete();
        DB::disconnect('pgsql_race_rival');
    }
});
