<?php

// LIFE-3: regression sentinel for PreAccountBuildService::createProvisionalUserWithRetry().
// HandleAllocator::allocate() is check-then-return (SELECT ... exists(), then the
// caller INSERTs) — a genuine TOCTOU window when two concurrent builds resolve the
// same seed and both allocate() calls land before either INSERT commits. The fix
// wraps each provisional-user create attempt in its own nested transaction so a
// core_users_handle_lc_unique violation only rolls back to a SAVEPOINT, leaving the
// outer build transaction healthy for the retry loop to re-consult allocate().
//
// Same PostgreSQL-only rationale as SiteProvisioningSavepointTest: the SQLite test
// schema has no handle_lc UNIQUE index, and SQLite doesn't abort a transaction on a
// statement error the way Postgres does — a 23505 (and the poisoning it would cause
// without a savepoint) simply cannot be reproduced there.
//
// To run against Supabase dev (or any real Postgres):
//   DB_CONNECTION=pgsql DB_HOST=... DB_PORT=... DB_DATABASE=... \
//   DB_USERNAME=... DB_PASSWORD=... \
//   php artisan test --filter PreAccountBuildHandleRaceTest

use App\Models\Core\Staff\PartnaStaff;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\User\HandleAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

if (! function_exists('handleRaceSuiteIsPostgres')) {
    function handleRaceSuiteIsPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}

// Staff actor: skips the IP abuse-cap lock entirely so these tests isolate the
// handle-collision path. Unsaved model — associate() only reads the key.
if (! function_exists('makeHandleRaceStaff')) {
    function makeHandleRaceStaff(): PartnaStaff
    {
        $staff = new PartnaStaff;
        $staff->id = (string) Str::uuid();
        $staff->auth_user_id = (string) Str::uuid();
        $staff->role = PartnaStaff::ROLE_ADMIN;

        return $staff;
    }
}

beforeEach(function () {
    Queue::fake();
});

// ─── 1. Mechanism sentinel — savepoints isolate repeated 23505s from the outer tx ───

it('a nested transaction isolates repeated unique-violations so the outer transaction survives', function () {
    if (! handleRaceSuiteIsPostgres()) {
        $this->markTestSkipped('PostgreSQL transaction-abort semantics required; SQLite cannot reproduce this.');
    }

    $table = 'handle_race_probe_'.Str::lower(Str::random(8));
    DB::statement("CREATE TEMPORARY TABLE {$table} (val text UNIQUE)");

    try {
        // Outer transaction == the build transaction in PreAccountBuildService::requestBuild().
        $result = DB::transaction(function () use ($table) {
            DB::table($table)->insert(['val' => 'taken']);

            // Three savepoint-isolated collisions in a row — mirrors the retry loop
            // in createProvisionalUserWithRetry() hitting the same colliding handle.
            $caughtCount = 0;
            for ($i = 0; $i < 3; $i++) {
                try {
                    DB::transaction(function () use ($table) {
                        DB::table($table)->insert(['val' => 'taken']); // → 23505 every time
                    });
                } catch (QueryException $e) {
                    if ($e->getCode() === '23505') {
                        $caughtCount++;
                    }
                }
            }

            // The poison test: this statement runs in the SAME outer transaction.
            // Pre-fix (no savepoint) the outer tx would be aborted and this throws 25P02.
            DB::table($table)->insert(['val' => 'free']);

            return ['caughtCount' => $caughtCount, 'rows' => DB::table($table)->count()];
        });

        expect($result['caughtCount'])->toBe(3, 'Expected all three duplicate inserts to raise 23505.');
        expect($result['rows'])->toBe(2, 'Outer transaction should hold both the baseline and the retry row.');
    } finally {
        DB::statement("DROP TABLE IF EXISTS {$table}");
    }
});

it('without a savepoint, a repeated 23505 poisons the whole outer transaction (negative control)', function () {
    if (! handleRaceSuiteIsPostgres()) {
        $this->markTestSkipped('PostgreSQL transaction-abort semantics required; SQLite cannot reproduce this.');
    }

    $table = 'handle_race_probe_'.Str::lower(Str::random(8));
    DB::statement("CREATE TEMPORARY TABLE {$table} (val text UNIQUE)");

    try {
        DB::beginTransaction();
        DB::table($table)->insert(['val' => 'taken']);

        try {
            DB::table($table)->insert(['val' => 'taken']); // → 23505, no savepoint
        } catch (QueryException $e) {
            // Swallowed at PHP level, but the PG transaction is now aborted.
        }

        $poisoned = false;
        try {
            DB::table($table)->insert(['val' => 'free']); // → 25P02
        } catch (QueryException $e) {
            $poisoned = $e->getCode() === '25P02';
        }

        expect($poisoned)->toBeTrue(
            'Expected the aborted-transaction state (25P02) when no savepoint isolates the 23505.'
        );
    } finally {
        DB::rollBack();
        DB::statement("DROP TABLE IF EXISTS {$table}");
    }
});

// ─── 2. Real-service integration — createProvisionalUserWithRetry under a forced collision ───
//
// HandleAllocator itself is check-then-return, so it can't be made to hand back an
// already-taken value through its own real logic without a genuine second process
// racing it. We stand in for that race by mocking allocate() to keep returning an
// ALREADY-TAKEN handle_lc for the first N calls, forcing the real savepoint-retry
// loop in PreAccountBuildService to absorb real Postgres 23505s raised by
// core_users_handle_lc_unique. Everything runs inside a test-owned outer
// transaction that is rolled back at the end, so nothing persists to the shared DB.

it('retries past a colliding handle_lc without aborting the build transaction', function () {
    if (! handleRaceSuiteIsPostgres()) {
        $this->markTestSkipped('PostgreSQL transaction-abort semantics required; SQLite cannot reproduce this.');
    }

    $existingId = (string) Str::uuid();

    try {
        DB::beginTransaction();

        try {
            // Pre-existing provisional user occupying the handle two mocked
            // allocate() calls will (wrongly) hand out.
            DB::table('core.users')->insert([
                'id' => $existingId,
                'auth_user_id' => null,
                'handle' => 'collision',
                'handle_lc' => 'collision',
                'display_name' => 'Existing',
                'primary_email' => null,
                'first_name' => 'Existing',
                'account_type' => 'partna',
                'status' => 'unclaimed',
            ]);
        } catch (QueryException $e) {
            DB::rollBack();
            $this->markTestSkipped('Cannot seed the core.users row as this role: '.$e->getMessage());
        }

        // First two allocate() calls return the ALREADY-TAKEN handle (simulating the
        // race window); the third returns a genuinely free one.
        $mockAllocator = Mockery::mock(HandleAllocator::class);
        $mockAllocator->shouldReceive('allocate')
            ->times(3)
            ->andReturn(
                ['handle' => 'collision', 'handle_lc' => 'collision'],
                ['handle' => 'collision', 'handle_lc' => 'collision'],
                ['handle' => 'collision-free', 'handle_lc' => 'collision-free'],
            );
        app()->instance(HandleAllocator::class, $mockAllocator);

        $result = app(PreAccountBuildService::class)->requestBuild(
            accountType: 'partna', sourceType: 'instagram', rawSourceRef: 'handleracewinner',
            sourceName: null, ipHash: null, staff: makeHandleRaceStaff(),
        );

        expect($result['build']->user->handle_lc)->toBe('collision-free');

        // Outer transaction still alive: the build row committed (to the savepoint)
        // in the same tx as the pre-seeded collision row.
        $stillAlive = DB::table('core.pre_account_builds')->where('id', $result['build']->id)->exists();
        expect($stillAlive)->toBeTrue('Outer transaction was aborted — the build row is not visible.');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

it('exhausts retries cleanly (PreAccountBuildException) when every attempt collides, without poisoning the transaction', function () {
    if (! handleRaceSuiteIsPostgres()) {
        $this->markTestSkipped('PostgreSQL transaction-abort semantics required; SQLite cannot reproduce this.');
    }

    $existingId = (string) Str::uuid();

    try {
        DB::beginTransaction();

        try {
            DB::table('core.users')->insert([
                'id' => $existingId,
                'auth_user_id' => null,
                'handle' => 'alwayscollision',
                'handle_lc' => 'alwayscollision',
                'display_name' => 'Existing',
                'primary_email' => null,
                'first_name' => 'Existing',
                'account_type' => 'partna',
                'status' => 'unclaimed',
            ]);
        } catch (QueryException $e) {
            DB::rollBack();
            $this->markTestSkipped('Cannot seed the core.users row as this role: '.$e->getMessage());
        }

        // Every allocate() call hands back the same already-taken handle — 5
        // straight collisions, matching the retry loop's exhaustion ceiling.
        $mockAllocator = Mockery::mock(HandleAllocator::class);
        $mockAllocator->shouldReceive('allocate')
            ->times(5)
            ->andReturn(['handle' => 'alwayscollision', 'handle_lc' => 'alwayscollision']);
        app()->instance(HandleAllocator::class, $mockAllocator);

        $thrown = null;
        try {
            app(PreAccountBuildService::class)->requestBuild(
                accountType: 'partna', sourceType: 'instagram', rawSourceRef: 'handleraceloser',
                sourceName: null, ipHash: null, staff: makeHandleRaceStaff(),
            );
        } catch (PreAccountBuildException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull('Expected a clean PreAccountBuildException, not a raw DB error.')
            ->and($thrown->errorCode)->toBe(PreAccountBuildException::SOURCE_REF_INVALID);

        // Outer transaction is still usable — no 25P02 poisoning from the collisions —
        // and the failed attempt left no partial rows behind.
        expect(DB::table('core.pre_account_builds')->count())->toBe(0);
        expect(DB::table('core.users')->count())->toBe(1); // only the pre-seeded row
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});
