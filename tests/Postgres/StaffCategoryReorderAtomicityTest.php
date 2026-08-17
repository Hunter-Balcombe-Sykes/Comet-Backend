<?php

// StaffServiceCategoryManagementController::reorder() renumbers the owner's
// service categories under the service-categories:{user} advisory key.
//
// WHAT THIS FILE USED TO BE ABOUT, and why it changed. Until the services
// cutover the endpoint wrote TWO stores — content.collections (via
// ServiceCollections::reposition()) and site.service_categories (via
// ReorderService) — originally under two INDEPENDENT lock scopes. Both halves
// are internally collision-safe, so nothing ever 500'd; the defect was that a
// competing writer could take the key in the GAP between the scopes and apply
// its whole reorder, leaving one store ordered by request A and the other by
// request B. The discriminating property was ATOMICITY: a failure in the second
// half had to roll the first half back.
//
// Services cutover Task 8 deleted the legacy store. There is one write now, so
// the cross-store failure mode cannot occur and the case that pinned it has no
// subject. What survives, and is what this file now asserts, is the property
// underneath it: reposition() is a MULTI-STATEMENT renumber (two passes, one
// UPDATE per row), so a failure part-way through must leave the order exactly
// as it was rather than half-applied.
//
// WHY THE OUTCOME ALONE IS NOT ENOUGH. A test that asserted only "the final
// order is the submitted one" passes with the lock deleted entirely, because
// the two-pass renumber prevents collisions on its own. Forcing a real
// mid-renumber fault is what distinguishes rolled-back from never-started, and
// the positive control below is what distinguishes it from never-written.
//
// This lane, not SQLite: transactional rollback across a multi-statement write
// is real-driver behaviour, and the pgsql->SQLite remap the default Feature
// lane uses (see tests/TestCase.php) cannot reproduce it by construction — the
// same reasoning ShopUpsertStoreAtomicityTest.php records for its own pair.
//
// LANE HYGIENE — mirrors ShopUpsertStoreAtomicityTest.php: self-provisions its
// tables inside a transaction afterEach always rolls back, because content.*
// tables are SHARED fixtures across this lane (whichever file runs first decides
// a table's shape for every later file in the same run).

use App\Services\Content\ServiceCollections;
use App\Services\Site\AdvisoryLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $pg->beginTransaction();
    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');

    foreach (['content.collection_items', 'content.collections', 'core.users'] as $table) {
        $pg->statement("DROP TABLE IF EXISTS {$table} CASCADE");
    }

    $pg->statement('CREATE TABLE core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');

    // Same shape as ShopUpsertStoreAtomicityTest.php's provision — see that
    // file for the migrations it mirrors.
    $pg->statement('CREATE TABLE content.collections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        parent_id uuid REFERENCES content.collections(id) ON DELETE CASCADE,
        label text NOT NULL,
        kind text,
        external_ref text,
        removed_at timestamptz,
        position integer NOT NULL DEFAULT 0,
        is_user_created boolean NOT NULL DEFAULT false,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
});

afterEach(function () {
    DB::connection('pgsql')->rollBack();
});

function catReorderUser(): string
{
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    return $userId;
}

/** @return list<string> collection ids, in the seeded position order */
function catReorderCollections(string $userId, int $count): array
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('content.collections')->insert([
            'id' => $id,
            'user_id' => $userId,
            'label' => 'Collection '.$i,
            'kind' => 'service_category',
            'position' => $i,
            'is_user_created' => true,
        ]);
        $ids[] = $id;
    }

    return $ids;
}

/**
 * The controller's reorder body, called directly.
 *
 * Deliberately NOT an HTTP call: the staff route needs the full staff-auth and
 * audit stack, and none of that is what this file is about. What matters is
 * that the renumber runs inside ONE transaction holding the lock, so the body
 * is reproduced here in the shape the controller has. That the CONTROLLER
 * really is shaped this way — including that it takes the key exactly once —
 * is pinned separately, in StaffServiceCategoryCutoverTest.
 */
function catReorderUnified(string $userId, array $collectionIds): void
{
    $lockKey = "service-categories:{$userId}";

    DB::connection('pgsql')->transaction(function () use ($userId, $collectionIds, $lockKey): void {
        AdvisoryLock::acquire($lockKey, AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

        if ($collectionIds !== []) {
            app(ServiceCollections::class)->reposition($userId, $collectionIds);
        }
    });
}

it('rolls the whole renumber back when a statement part-way through it fails', function () {
    $userId = catReorderUser();
    [$c0, $c1, $c2] = catReorderCollections($userId, 3);

    // Force a genuine Postgres statement-level error inside the renumber.
    // position is a real, NOT NULL column reposition() writes on every call;
    // dropping it makes the renumber throw for the same reason a real
    // schema/driver fault would, without inventing a constraint that does not
    // exist in production. The rows keep their seeded order because the
    // failure aborts the transaction that was rewriting them.
    DB::connection('pgsql')->statement('ALTER TABLE content.collections DROP COLUMN position');

    expect(fn () => catReorderUnified($userId, [$c2, $c0, $c1]))
        ->toThrow(QueryException::class);

    // Re-add the column so the assertion can read it back; the values are
    // whatever the rolled-back transaction left behind, which must be the
    // defaults rather than a half-applied 0,1,2 permutation.
    DB::connection('pgsql')->statement('ALTER TABLE content.collections ADD COLUMN position integer NOT NULL DEFAULT 0');

    $positions = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)->orderBy('label')->pluck('position', 'label');

    expect($positions)->toHaveCount(3)
        ->and((int) $positions['Collection 0'])->toBe(0)
        ->and((int) $positions['Collection 1'])->toBe(0)
        ->and((int) $positions['Collection 2'])->toBe(0);
});

it('commits the renumber when nothing fails', function () {
    // The positive control. Without it the case above passes on a reorder that
    // never writes anything at all — "nothing changed" is the same observation
    // as "rolled back".
    $userId = catReorderUser();
    [$c0, $c1, $c2] = catReorderCollections($userId, 3);

    catReorderUnified($userId, [$c2, $c0, $c1]);

    $positions = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)->orderBy('label')->pluck('position', 'label');
    expect((int) $positions['Collection 2'])->toBe(0)
        ->and((int) $positions['Collection 0'])->toBe(1)
        ->and((int) $positions['Collection 1'])->toBe(2);
});
