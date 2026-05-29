<?php

use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixture helper — also defined in BackfillLinkCategoriesTest.php.
 * Duplicated here so this file is self-contained when run in isolation
 * (global PHP functions from other test files aren't available unless
 * those files happen to be loaded first, which is not guaranteed).
 */
if (! function_exists('createBackfillFixtureIds')) {
    function createBackfillFixtureIds(): array
    {
        $userId = (string) Str::uuid();
        $siteId = (string) Str::uuid();

        DB::connection('pgsql')->table('core.users')->insert([
            'id'         => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('pgsql')->table('site.sites')->insert([
            'id'         => $siteId,
            'user_id'    => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$userId, $siteId];
    }
}

/**
 * Tests for partna:enforce-platform-link-cap — the one-shot data remediation
 * command that soft-deletes excess platform link blocks for professionals
 * already over the platform_links_max cap.
 *
 * Uses direct DB inserts to mirror BackfillLinkCategoriesTest's pattern:
 * no factory dependency, just enough rows to satisfy the command's queries.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();

    config(['partna.platform_links_max' => 7]);
    config(['partna.platform_links_categories' => ['social', 'content', 'events', 'streaming']]);
});

/**
 * Insert `count` capped-category link blocks for a professional with distinct
 * created_at timestamps so oldest-first ordering is deterministic.
 *
 * Returns the inserted block IDs in insertion order (oldest first).
 *
 * @return list<string>
 */
function seedCapBlocks(string $userId, string $siteId, int $count, string $category = 'social'): array
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $id = (string) Str::uuid();
        $ids[] = $id;
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => $id,
            'user_id' => $userId,
            'site_id' => $siteId,
            'block_group' => 'links',
            'block_type' => 'link',
            'settings' => json_encode(['category' => $category, 'platform' => 'instagram']),
            'sort_order' => $i,
            'is_active' => 1,
            'is_enabled' => 1,
            'created_at' => now()->subSeconds($count - $i)->toDateTimeString(), // oldest first
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    return $ids;
}

it('does not touch blocks when the professional is at the cap', function () {
    [$proId, $siteId] = createBackfillFixtureIds();
    $ids = seedCapBlocks($proId, $siteId, 7);

    Artisan::call('partna:enforce-platform-link-cap');

    $surviving = Block::query()->where('user_id', $proId)->whereNull('deleted_at')->count();
    expect($surviving)->toBe(7);
});

it('soft-deletes the newest excess blocks keeping the oldest cap worth', function () {
    [$proId, $siteId] = createBackfillFixtureIds();
    $ids = seedCapBlocks($proId, $siteId, 9); // 9 blocks, 2 over the cap

    Artisan::call('partna:enforce-platform-link-cap');

    $surviving = Block::query()
        ->where('user_id', $proId)
        ->whereNull('deleted_at')
        ->pluck('id')
        ->sort()
        ->values();

    // Exactly 7 survive
    expect($surviving)->toHaveCount(7);

    // The 7 survivors are the 7 oldest (first 7 IDs in insertion order)
    $expectedKept = collect($ids)->take(7)->sort()->values();
    expect($surviving->all())->toBe($expectedKept->all());

    // The 2 newest are soft-deleted
    $deleted = Block::withTrashed()
        ->where('user_id', $proId)
        ->whereNotNull('deleted_at')
        ->pluck('id');
    expect($deleted)->toHaveCount(2);
    expect($deleted->contains($ids[7]))->toBeTrue();
    expect($deleted->contains($ids[8]))->toBeTrue();
});

it('does not write in dry-run mode', function () {
    [$proId, $siteId] = createBackfillFixtureIds();
    seedCapBlocks($proId, $siteId, 9);

    Artisan::call('partna:enforce-platform-link-cap', ['--dry-run' => true]);

    $surviving = Block::query()->where('user_id', $proId)->whereNull('deleted_at')->count();
    expect($surviving)->toBe(9); // nothing touched
});

it('ignores blocks in non-capped categories', function () {
    [$proId, $siteId] = createBackfillFixtureIds();
    // 3 social (capped) + 6 booking (not capped) — only social count matters
    seedCapBlocks($proId, $siteId, 3, 'social');
    seedCapBlocks($proId, $siteId, 6, 'booking');

    Artisan::call('partna:enforce-platform-link-cap');

    $surviving = Block::query()->where('user_id', $proId)->whereNull('deleted_at')->count();
    expect($surviving)->toBe(9); // all 9 survive; social count (3) is under cap
});

it('is idempotent when run twice', function () {
    [$proId, $siteId] = createBackfillFixtureIds();
    seedCapBlocks($proId, $siteId, 9);

    Artisan::call('partna:enforce-platform-link-cap');
    Artisan::call('partna:enforce-platform-link-cap'); // second run

    $surviving = Block::query()->where('user_id', $proId)->whereNull('deleted_at')->count();
    expect($surviving)->toBe(7);
    $deleted = Block::withTrashed()->where('user_id', $proId)->whereNotNull('deleted_at')->count();
    expect($deleted)->toBe(2);
});

it('scopes remediation per professional — does not bleed across accounts', function () {
    [$proIdA, $siteIdA] = createBackfillFixtureIds();
    [$proIdB, $siteIdB] = createBackfillFixtureIds();

    seedCapBlocks($proIdA, $siteIdA, 9); // over cap
    seedCapBlocks($proIdB, $siteIdB, 5); // under cap

    Artisan::call('partna:enforce-platform-link-cap');

    expect(Block::query()->where('user_id', $proIdA)->whereNull('deleted_at')->count())->toBe(7);
    expect(Block::query()->where('user_id', $proIdB)->whereNull('deleted_at')->count())->toBe(5);
});
