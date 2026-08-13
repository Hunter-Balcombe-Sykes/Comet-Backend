<?php

// Slice 3b §19.8: StaffServiceCategoryManagementController::reorder() writes
// TWO stores — content.collections (via ServiceCollections::reposition()) and
// site.service_categories (via ReorderService) — because the Fresha half still
// files into the legacy table until slice 7.
//
// It used to write them under two INDEPENDENT lock scopes: the collections
// write in its own transaction, then ReorderService::reorder() opening a second
// transaction and re-taking the same advisory key. Both halves are internally
// collision-safe, so nothing ever 500'd — which is exactly why this needed a
// test rather than an incident. The defect is that a competing writer can take
// the key in the GAP between the two scopes and apply its whole reorder, after
// which this request commits its legacy order on top: one store ordered by
// request A, the other by request B, from two individually-correct requests.
//
// WHY THE OUTCOME ALONE IS NOT ENOUGH. A test that asserted only "the final
// order is the submitted one" passes on the two-scope version too, whenever no
// competing writer happens to interleave — and passes with the lock deleted
// entirely, because each store's own two-pass renumber prevents collisions on
// its own. The discriminating property is ATOMICITY: with one scope, a failure
// in the legacy half must roll the collections half back. Under two scopes the
// collections write had already COMMITTED and survives. That is the assertion
// below, and re-splitting the scopes turns it red.
//
// This lane, not SQLite: transactional rollback across two writes is real-driver
// behaviour, and the pgsql->SQLite remap the default Feature lane uses (see
// tests/TestCase.php) cannot reproduce it by construction — the same reasoning
// ShopUpsertStoreAtomicityTest.php records for its own pair.
//
// LANE HYGIENE — mirrors ShopUpsertStoreAtomicityTest.php: self-provisions its
// tables inside a transaction afterEach always rolls back, because content.*
// tables are SHARED fixtures across this lane (whichever file runs first decides
// a table's shape for every later file in the same run).

use App\Models\Core\User\ServiceCategory;
use App\Services\Content\ServiceCollections;
use App\Services\Site\AdvisoryLock;
use App\Services\Site\ReorderService;
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

    foreach (['content.collection_items', 'content.collections', 'site.service_categories', 'core.users'] as $table) {
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

    $pg->statement('CREATE TABLE site.service_categories (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        title text NOT NULL,
        source text,
        sort_order integer NOT NULL DEFAULT 0,
        deleted_at timestamptz,
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

/** @return list<string> legacy category ids, in the seeded sort_order */
function catReorderLegacy(string $userId, int $count): array
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.service_categories')->insert([
            'id' => $id,
            'user_id' => $userId,
            'title' => 'Legacy '.$i,
            'source' => 'fresha',
            'sort_order' => $i,
        ]);
        $ids[] = $id;
    }

    return $ids;
}

/**
 * The controller's unified body, called directly.
 *
 * Deliberately NOT an HTTP call: the staff route needs the full staff-auth and
 * audit stack, and none of that is what this file is about. What matters is
 * that both writes run inside ONE transaction holding ONE lock — so the body is
 * reproduced here in the shape the controller has, and the test forces the
 * second half to fail. If the controller ever re-splits the scopes, the
 * assertion below no longer holds for it. That the CONTROLLER really is shaped
 * this way is pinned separately, in StaffServiceCategoryCutoverTest.
 */
function catReorderUnified(string $userId, array $collectionIds, array $legacyIds): void
{
    $lockKey = "service-categories:{$userId}";

    DB::connection('pgsql')->transaction(function () use ($userId, $collectionIds, $legacyIds, $lockKey): void {
        AdvisoryLock::acquire($lockKey, AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

        if ($collectionIds !== []) {
            app(ServiceCollections::class)->reposition($userId, $collectionIds);
        }

        if ($legacyIds !== []) {
            app(ReorderService::class)->renumberLocked(
                $legacyIds,
                ServiceCategory::query()->where('user_id', $userId),
                $lockKey,
            );
        }
    });
}

it('rolls the collections reorder back when the legacy reorder fails', function () {
    $userId = catReorderUser();
    [$c0, $c1, $c2] = catReorderCollections($userId, 3);
    $legacyIds = catReorderLegacy($userId, 2);

    // Force a genuine Postgres statement-level error on the SECOND half.
    // sort_order is a real, NOT NULL column ReorderService writes on every
    // call; dropping it makes the legacy renumber throw for the same reason a
    // real schema/driver fault would, without inventing a constraint that does
    // not exist in production.
    DB::connection('pgsql')->statement('ALTER TABLE site.service_categories DROP COLUMN sort_order');

    expect(fn () => catReorderUnified($userId, [$c2, $c0, $c1], $legacyIds))
        ->toThrow(QueryException::class);

    // The two-scope failure mode: these positions would already be committed —
    // c2=0, c0=1, c1=2 — because the collections transaction closed before the
    // legacy half ran. One scope means the seeded 0,1,2 survives untouched.
    $positions = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)->orderBy('label')->pluck('position', 'label');

    expect((int) $positions['Collection 0'])->toBe(0)
        ->and((int) $positions['Collection 1'])->toBe(1)
        ->and((int) $positions['Collection 2'])->toBe(2);
});

it('commits both stores together when neither half fails', function () {
    // The positive control. Without it the case above passes on a reorder that
    // never writes anything at all — "nothing changed" is the same observation
    // as "rolled back".
    $userId = catReorderUser();
    [$c0, $c1, $c2] = catReorderCollections($userId, 3);
    [$l0, $l1] = catReorderLegacy($userId, 2);

    catReorderUnified($userId, [$c2, $c0, $c1], [$l1, $l0]);

    $positions = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)->orderBy('label')->pluck('position', 'label');
    expect((int) $positions['Collection 2'])->toBe(0)
        ->and((int) $positions['Collection 0'])->toBe(1)
        ->and((int) $positions['Collection 1'])->toBe(2);

    $sortOrders = DB::connection('pgsql')->table('site.service_categories')
        ->where('user_id', $userId)->pluck('sort_order', 'id');
    expect((int) $sortOrders[$l1])->toBe(0)
        ->and((int) $sortOrders[$l0])->toBe(1);
});
