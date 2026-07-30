<?php

use App\Console\Commands\PruneRetiredItemSlugs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 271-PRIV-1: site.item_slugs had no retention column and no purge job, so
// retired (is_current = false) rows accumulated forever. This pins
// slugs:prune-retired's two predicates: (1) properly retired rows past the
// config window, and (2) stranded is_current=false/retired_at=NULL rows
// (a crashed rename) older than the window.

beforeEach(function () {
    setupItemSlugsTable();
});

function insertItemSlugRow(array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.item_slugs')->insert(array_merge([
        'id' => $id,
        'user_id' => 'user-1',
        'item_type' => 'menu_item',
        'item_key' => 'k1',
        'slug' => 'fish-tacos',
        'is_current' => 1,
        'created_at' => now()->toDateTimeString(),
        'retired_at' => null,
    ], $overrides));

    return $id;
}

it('deletes a retired slug past the retention window', function () {
    $id = insertItemSlugRow([
        'slug' => 'old-name', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $id)->exists())->toBeFalse();
});

it('keeps a retired slug still inside the retention window', function () {
    $id = insertItemSlugRow([
        'slug' => 'old-name', 'is_current' => 0,
        'created_at' => now()->subDays(10)->toDateTimeString(),
        'retired_at' => now()->subDays(10)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $id)->exists())->toBeTrue();
});

it('keeps a live current slug regardless of age', function () {
    $id = insertItemSlugRow([
        'slug' => 'fish-tacos', 'is_current' => 1,
        'created_at' => now()->subDays(500)->toDateTimeString(),
        'retired_at' => null,
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $id)->exists())->toBeTrue();
});

it('deletes a stranded is_current=false row with no retired_at older than the window', function () {
    // The only way this shape occurs: a crash between insertUnique(..., false)
    // and promote() in ensureCurrent() -- those two calls are not one transaction.
    $id = insertItemSlugRow([
        'slug' => 'stranded', 'is_current' => 0, 'retired_at' => null,
        'created_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $id)->exists())->toBeFalse();
});

it('keeps a stranded is_current=false row with no retired_at that is newer than the window', function () {
    $id = insertItemSlugRow([
        'slug' => 'stranded-fresh', 'is_current' => 0, 'retired_at' => null,
        'created_at' => now()->subDays(1)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $id)->exists())->toBeTrue();
});

it('boundary: a retired_at exactly at the cutoff is kept, not deleted', function () {
    // config default is 90 days; cutoff = now()->subDays(90). Stamping retired_at
    // to that exact instant must survive: the predicate is `<`, not `<=`, matching
    // PruneExpiredHandleAliases:29.
    $cutoff = now()->subDays((int) config('partna.item_slugs.retirement_days', 90));
    $id = insertItemSlugRow([
        'slug' => 'boundary', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => $cutoff->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $id)->exists())->toBeTrue();
});

it('--dry-run reports counts and deletes nothing', function () {
    $expiredId = insertItemSlugRow([
        'slug' => 'old-name', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $strandedId = insertItemSlugRow([
        'slug' => 'stranded', 'is_current' => 0, 'retired_at' => null,
        'created_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class, ['--dry-run' => true])
        ->expectsOutputToContain('Expired retired item slugs: 1')
        ->expectsOutputToContain('Stranded unstamped item slugs: 1')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $expiredId)->exists())->toBeTrue();
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $strandedId)->exists())->toBeTrue();
});

it('is global, not tenant-scoped: a second user\'s expired retired row is also deleted', function () {
    $otherId = insertItemSlugRow([
        'user_id' => 'user-2', 'slug' => 'someone-elses-old-name', 'is_current' => 0,
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'retired_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneRetiredItemSlugs::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('id', $otherId)->exists())->toBeFalse();
});
