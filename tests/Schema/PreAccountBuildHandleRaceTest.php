<?php

// LIFE-3 (moved to the applied-schema lane, tranche 3 of COV-LANE): regression
// sentinel for PreAccountBuildService::createProvisionalUserWithRetry().
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
// without a savepoint) simply cannot be reproduced there. SchemaTestCase's setUp()
// requires a real, migrated Postgres connection.
//
// Two fixes made against real Postgres that the original SQLite-only version never
// exercised:
//
// 1. makeHandleRaceStaff() built an UNSAVED PartnaStaff (id set, never persisted) —
//    "associate() only reads the key" was true under SQLite, which has no FK from
//    pre_account_builds.built_by_staff_id onto core.partna_staff. Real Postgres DOES
//    enforce pre_account_builds_built_by_staff_id_fkey, so $build->save() 23503'd on
//    every staff-attributed build. seedRaceStaff() below inserts a real
//    core.partna_staff row (with its own auth.users parent) so the FK is satisfied.
//
// 2. The two `expect(DB::table('core.users')->count())->toBe(1)` /
//    `->toBe(...)` style assertions counted the ENTIRE table. That was only ever
//    true because the SQLite lane starts each test against an empty in-memory
//    core.users — this lane's database is real, persistent, and shared (per
//    SchemaTestCase's docblock), and the cloned template already carries pre-existing
//    rows from other fixtures. The assertions are rewritten to scope by the specific
//    handle_lc values this test controls, which is what "no partial rows were left
//    behind by the retry attempts" actually means.

use App\Models\Core\Staff\PartnaStaff;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\PreAccount\SourcePrefetch;
use App\Services\User\HandleAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

/**
 * Insert a real core.partna_staff row (with its own auth.users parent) on the
 * SAME connection/transaction the caller is already inside, so a caller-managed
 * DB::rollBack() undoes it along with everything else — no separate cleanup call
 * needed. Mirrors Tests\Schema\Concerns\SeedsAuthUsers::seedAuthUser() for the
 * staff table, which has an identical auth_user_id FK.
 */
function seedRaceStaff(): PartnaStaff
{
    $authUserId = (string) Str::uuid();
    DB::table('auth.users')->insert([
        'id' => $authUserId,
        'email' => $authUserId.'@schema-lane.test',
    ]);

    return PartnaStaff::factory()->create([
        'auth_user_id' => $authUserId,
        'role' => PartnaStaff::ROLE_ADMIN,
    ]);
}

beforeEach(function () {
    Queue::fake();
});

// ─── 1. Mechanism sentinel — savepoints isolate repeated 23505s from the outer tx ───

it('a nested transaction isolates repeated unique-violations so the outer transaction survives', function () {
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
    $existingId = (string) Str::uuid();
    $handleLc = 'collision'.Str::lower(Str::random(6));
    $handleLcFree = $handleLc.'-free';

    try {
        DB::beginTransaction();

        try {
            // Pre-existing provisional user occupying the handle two mocked
            // allocate() calls will (wrongly) hand out.
            DB::table('core.users')->insert([
                'id' => $existingId,
                'auth_user_id' => null,
                'handle' => $handleLc,
                'handle_lc' => $handleLc,
                'display_name' => 'Existing',
                'primary_email' => null,
                'first_name' => 'Existing',
                'account_type' => 'partna',
                'status' => 'unclaimed',
            ]);

            $staff = seedRaceStaff();
        } catch (QueryException $e) {
            DB::rollBack();
            $this->markTestSkipped('Cannot seed the core.users/partna_staff FK chain as this role: '.$e->getMessage());
        }

        // First two allocate() calls return the ALREADY-TAKEN handle (simulating the
        // race window); the third returns a genuinely free one.
        $mockAllocator = Mockery::mock(HandleAllocator::class);
        $mockAllocator->shouldReceive('allocate')
            ->times(3)
            ->andReturn(
                ['handle' => $handleLc, 'handle_lc' => $handleLc],
                ['handle' => $handleLc, 'handle_lc' => $handleLc],
                ['handle' => $handleLcFree, 'handle_lc' => $handleLcFree],
            );
        app()->instance(HandleAllocator::class, $mockAllocator);

        $service = app(PreAccountBuildService::class);
        $result = $service->requestBuild(
            accountType: 'partna', sourceType: 'instagram', rawSourceRef: 'handleracewinner'.Str::random(6),
            sourceName: null, ipHash: null, staff: $staff,
        );

        // Item 1a (c3962a0a1, 2026-09-02): requestBuild no longer allocates a
        // handle or creates the provisional user — materializeIdentity does,
        // once the scrape has verified the source. The savepoint isolation
        // this test exists to prove still lives in
        // createProvisionalUserWithRetry; only the entry point moved.
        $user = $service->materializeIdentity($result['build'], new SourcePrefetch(payload: []));

        expect($user->handle_lc)->toBe($handleLcFree)
            ->and((string) $result['build']->refresh()->user_id)->toBe((string) $user->id);

        // Outer transaction still alive: the build row committed (to the savepoint)
        // in the same tx as the pre-seeded collision row.
        $stillAlive = DB::table('core.pre_account_builds')->where('id', $result['build']->id)->exists();
        expect($stillAlive)->toBeTrue('Outer transaction was aborted — the build row is not visible.');

        // Scoped, not a whole-table count: this lane's core.users is a real, shared,
        // persistent table (unlike SQLite's empty-per-test one) — only THIS test's
        // two handle_lc values are its business. Exactly one row per handle survives
        // (the pre-seed, and the one the retry finally created); no ghost row from
        // either aborted attempt at $handleLc leaked past its savepoint rollback.
        expect(DB::table('core.users')->whereIn('handle_lc', [$handleLc, $handleLcFree])->count())->toBe(2);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

it('exhausts retries cleanly (PreAccountBuildException) when every attempt collides, without poisoning the transaction', function () {
    $existingId = (string) Str::uuid();
    $handleLc = 'alwayscollision'.Str::lower(Str::random(6));

    try {
        DB::beginTransaction();

        try {
            DB::table('core.users')->insert([
                'id' => $existingId,
                'auth_user_id' => null,
                'handle' => $handleLc,
                'handle_lc' => $handleLc,
                'display_name' => 'Existing',
                'primary_email' => null,
                'first_name' => 'Existing',
                'account_type' => 'partna',
                'status' => 'unclaimed',
            ]);

            $staff = seedRaceStaff();
        } catch (QueryException $e) {
            DB::rollBack();
            $this->markTestSkipped('Cannot seed the core.users/partna_staff FK chain as this role: '.$e->getMessage());
        }

        // Every allocate() call hands back the same already-taken handle — 5
        // straight collisions, matching the retry loop's exhaustion ceiling.
        $mockAllocator = Mockery::mock(HandleAllocator::class);
        $mockAllocator->shouldReceive('allocate')
            ->times(5)
            ->andReturn(['handle' => $handleLc, 'handle_lc' => $handleLc]);
        app()->instance(HandleAllocator::class, $mockAllocator);

        $service = app(PreAccountBuildService::class);
        $build = $service->requestBuild(
            accountType: 'partna', sourceType: 'instagram', rawSourceRef: 'handleraceloser'.Str::random(6),
            sourceName: null, ipHash: null, staff: $staff,
        )['build'];

        $thrown = null;
        try {
            // Item 1a: the exhaustion path is reached through
            // materializeIdentity now, not requestBuild.
            $service->materializeIdentity($build, new SourcePrefetch(payload: []));
        } catch (PreAccountBuildException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull('Expected a clean PreAccountBuildException, not a raw DB error.')
            ->and($thrown->errorCode)->toBe(PreAccountBuildException::SOURCE_REF_INVALID);

        // Outer transaction is still usable — no 25P02 poisoning from the collisions.
        //
        // The BUILD row survives, and must: since Item 1a (c3962a0a1) requestBuild
        // creates it before any identity exists, and F3 resets a failed build so it
        // re-runs. Deleting it here would break that retry path. This assertion
        // used to expect 0 because the pre-Item-1a requestBuild created build and
        // user together, so an exhausted allocation rolled BOTH back.
        //
        // What must NOT survive is a half-made identity: no user bound to the
        // build, and no ghost core.users row past the savepoint rollback. Scoped
        // to this test's own handle_lc (see the header comment: a whole-table
        // count only ever passed because the SQLite lane's core.users starts
        // empty, which this lane's real, shared, persistent database does not).
        $builds = DB::table('core.pre_account_builds')->where('source_type', 'instagram')->where('source_ref_lc', 'like', 'handleraceloser%');
        expect((clone $builds)->count())->toBe(1)
            ->and((clone $builds)->whereNotNull('user_id')->count())->toBe(0);
        expect(DB::table('core.users')->where('handle_lc', $handleLc)->count())->toBe(1); // only the pre-seeded row
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});
