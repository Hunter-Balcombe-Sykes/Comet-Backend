<?php

use App\Console\Commands\PruneRetiredItemSlugs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 271-PRIV-1: the item-slug registry had no retention column and no purge job,
// so retired (is_current = false) rows accumulated forever. This pins
// slugs:prune-retired's two predicates: (1) properly retired rows past the
// config window, and (2) stranded is_current=false/retired_at=NULL rows
// (a crashed rename) older than the window.
//
// Both arms are live against content.item_slugs. ContentItemSlugAllocator
// stamps retired_at on rename and inserts a non-current row before promote()
// stamps it (so a crash between the two really does strand one), ItemMerger
// ::moveSlugs() stamps retired_at on merge, and PoolResolver::itemPayloads()
// reads slug/aliases from the table on every pool resolve.
//
// These cases were originally written against site.item_slugs and were ported
// here verbatim when that table was dropped (20260819130000) -- its writers had
// been retired in slice 7 Phase 6, which left both predicates unsatisfiable.
// The boundary and cross-tenant cases in particular exist only here now; do not
// drop them as duplicates.

beforeEach(function () {
    setupContentCurationTables();
});

function insertContentItemSlugRow(array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('content.item_slugs')->insert(array_merge([
        'id' => $id,
        'user_id' => 'user-1',
        'item_id' => (string) Str::uuid(),
        'slug' => 'fish-tacos',
        'is_current' => 1,
        'created_at' => now()->toDateTimeString(),
        'retired_at' => null,
    ], $overrides));

    return $id;
}

function contentSlugExists(string $id): bool
{
    return DB::connection('pgsql')->table('content.item_slugs')->where('id', $id)->exists();
}

it('deletes a retired slug past the retention window', function () {
    $id = insertContentItemSlugRow([
        'slug' => 'old-name', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(contentSlugExists($id))->toBeFalse();
});

it('keeps a retired slug still inside the retention window', function () {
    $id = insertContentItemSlugRow([
        'slug' => 'old-name', 'is_current' => 0,
        'created_at' => now()->subDays(10)->toDateTimeString(),
        'retired_at' => now()->subDays(10)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(contentSlugExists($id))->toBeTrue();
});

it('keeps a live current slug regardless of age', function () {
    $id = insertContentItemSlugRow([
        'slug' => 'fish-tacos', 'is_current' => 1,
        'created_at' => now()->subDays(500)->toDateTimeString(),
        'retired_at' => null,
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(contentSlugExists($id))->toBeTrue();
});

it('deletes a stranded is_current=false row with no retired_at older than the window', function () {
    // The only way this shape occurs: a crash between the insert and promote()
    // in ContentItemSlugAllocator::allocate() -- those are not one transaction.
    $id = insertContentItemSlugRow([
        'slug' => 'stranded', 'is_current' => 0, 'retired_at' => null,
        'created_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(contentSlugExists($id))->toBeFalse();
});

it('keeps a stranded is_current=false row with no retired_at that is newer than the window', function () {
    $id = insertContentItemSlugRow([
        'slug' => 'stranded-fresh', 'is_current' => 0, 'retired_at' => null,
        'created_at' => now()->subDays(1)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(contentSlugExists($id))->toBeTrue();
});

it('boundary: a retired_at exactly at the cutoff is kept, not deleted', function () {
    // config default is 90 days; cutoff = now()->subDays(90). Stamping retired_at
    // to that exact instant must survive: the predicate is `<`, not `<=`, matching
    // PruneExpiredHandleAliases:29.
    $cutoff = now()->subDays((int) config('partna.item_slugs.retirement_days', 90));
    $id = insertContentItemSlugRow([
        'slug' => 'boundary', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => $cutoff->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(contentSlugExists($id))->toBeTrue();
});

it('is global, not tenant-scoped: a second user\'s expired retired row is also deleted', function () {
    $otherId = insertContentItemSlugRow([
        'user_id' => 'user-2', 'slug' => 'someone-elses-old-name', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(contentSlugExists($otherId))->toBeFalse();
});

it('--dry-run reports both counts separately and deletes nothing', function () {
    $expiredId = insertContentItemSlugRow([
        'slug' => 'old-name', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $strandedId = insertContentItemSlugRow([
        'slug' => 'stranded', 'is_current' => 0, 'retired_at' => null,
        'created_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class, ['--dry-run' => true])
        ->expectsOutputToContain('Expired retired item slugs (content): 1')
        ->expectsOutputToContain('Stranded unstamped item slugs (content): 1')
        ->assertSuccessful();

    expect(contentSlugExists($expiredId))->toBeTrue();
    expect(contentSlugExists($strandedId))->toBeTrue();
});

it('sweeps both predicates in the same real run, reported with separate counts', function () {
    $expiredId = insertContentItemSlugRow([
        'slug' => 'content-old', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $strandedId = insertContentItemSlugRow([
        'slug' => 'stranded-content', 'is_current' => 0, 'retired_at' => null,
        'created_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)
        ->expectsOutputToContain('Expired retired item slugs (content): 1')
        ->expectsOutputToContain('Stranded unstamped item slugs (content): 1')
        ->assertSuccessful();

    expect(contentSlugExists($expiredId))->toBeFalse();
    expect(contentSlugExists($strandedId))->toBeFalse();
});
