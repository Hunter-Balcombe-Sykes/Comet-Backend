<?php

use App\Services\Content\ContentItemSlugAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupContentCurationTables();
});

function slugItem(string $userId, ?string $headline, string $kind = 'event'): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $kind,
        'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('mints a readable slug and returns it', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'Beginner sewing workshop');

    $slug = app(ContentItemSlugAllocator::class)->ensureCurrent($pro->id, $itemId, 'Beginner sewing workshop');

    expect($slug)->toBe('beginner-sewing-workshop');
    expect(DB::table('content.item_slugs')->where('item_id', $itemId)->where('is_current', true)->count())->toBe(1);
});

it('is idempotent — a second call mints nothing new', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'Clothes swap');
    $allocator = app(ContentItemSlugAllocator::class);

    $allocator->ensureCurrent($pro->id, $itemId, 'Clothes swap');
    $allocator->ensureCurrent($pro->id, $itemId, 'Clothes swap');

    expect(DB::table('content.item_slugs')->where('item_id', $itemId)->count())->toBe(1);
});

// idx_item_slugs_unique is (user_id, slug) and NON-partial, so two items with
// the same headline must not fight over one name.
it('suffixes a collision within one profile', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $allocator = app(ContentItemSlugAllocator::class);

    $first = $allocator->ensureCurrent($pro->id, slugItem($pro->id, 'Grant writing'), 'Grant writing');
    $second = $allocator->ensureCurrent($pro->id, slugItem($pro->id, 'Grant writing'), 'Grant writing');

    expect($first)->toBe('grant-writing');
    expect($second)->toBe('grant-writing-2');
});

it('lets two profiles hold the same slug', function () {
    $a = createTenant('slug-'.Str::lower(Str::random(6)));
    $b = createTenant('slug-'.Str::lower(Str::random(6)));
    $allocator = app(ContentItemSlugAllocator::class);

    expect($allocator->ensureCurrent($a->id, slugItem($a->id, 'Open day'), 'Open day'))->toBe('open-day');
    expect($allocator->ensureCurrent($b->id, slugItem($b->id, 'Open day'), 'Open day'))->toBe('open-day');
});

// The whole point of retiring rather than deleting: an old URL must 301.
// There is no one_current partial index on this table, so the demote/promote
// pair has to be correct in code — assert exactly one current row survives.
it('retires the old slug on a rename and keeps it as an alias', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'Old name');
    $allocator = app(ContentItemSlugAllocator::class);

    $allocator->ensureCurrent($pro->id, $itemId, 'Old name');
    $new = $allocator->ensureCurrent($pro->id, $itemId, 'New name');

    expect($new)->toBe('new-name');
    expect(DB::table('content.item_slugs')->where('item_id', $itemId)->where('is_current', true)->count())->toBe(1);

    $map = $allocator->lookupCurrent($pro->id, [$itemId]);
    expect($map[$itemId]['slug'])->toBe('new-name');
    expect($map[$itemId]['aliases'])->toContain('old-name');
    // The raw id is always an alias — a link predating slugs must keep working.
    expect($map[$itemId]['aliases'])->toContain($itemId);
});

it('reactivates a slug the item already owns rather than minting a suffix', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'First');
    $allocator = app(ContentItemSlugAllocator::class);

    $allocator->ensureCurrent($pro->id, $itemId, 'First');
    $allocator->ensureCurrent($pro->id, $itemId, 'Second');
    $back = $allocator->ensureCurrent($pro->id, $itemId, 'First');

    expect($back)->toBe('first');
    expect(DB::table('content.item_slugs')->where('item_id', $itemId)->count())->toBe(2);
    expect(DB::table('content.item_slugs')->where('item_id', $itemId)->where('is_current', true)->count())->toBe(1);
});

// retired_at was added by 20260731210000 with NO backfill, so a pre-existing
// retired row can be is_current=false AND retired_at NULL. It must still serve
// as an alias while it is inside the window, and stop when it ages out.
it('serves a stranded retired row off created_at and drops it once stale', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'Current');
    $allocator = app(ContentItemSlugAllocator::class);
    $allocator->ensureCurrent($pro->id, $itemId, 'Current');

    DB::table('content.item_slugs')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $pro->id, 'item_id' => $itemId,
        'slug' => 'stranded-fresh', 'is_current' => false,
        'created_at' => now()->subDays(5), 'retired_at' => null,
    ]);
    DB::table('content.item_slugs')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $pro->id, 'item_id' => $itemId,
        'slug' => 'stranded-stale', 'is_current' => false,
        'created_at' => now()->subDays(400), 'retired_at' => null,
    ]);

    $aliases = $allocator->lookupCurrent($pro->id, [$itemId])[$itemId]['aliases'];

    expect($aliases)->toContain('stranded-fresh');
    expect($aliases)->not->toContain('stranded-stale');
});

it('returns nothing for an item with no current slug', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'Unslugged');

    expect(app(ContentItemSlugAllocator::class)->lookupCurrent($pro->id, [$itemId]))->toBe([]);
});

// ── the backfill command ────────────────────────────────────────────────

it('backfills event items and reports the counts', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'Welcoming community dinner');

    $this->artisan('content:backfill-item-slugs')
        ->expectsOutputToContain('minted 1')
        ->assertExitCode(0);

    expect(DB::table('content.item_slugs')->where('item_id', $itemId)->value('slug'))
        ->toBe('welcoming-community-dinner');
});

it('writes nothing under --dry-run', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    slugItem($pro->id, 'Rotary disco');

    $this->artisan('content:backfill-item-slugs --dry-run')
        ->expectsOutputToContain('would mint 1')
        ->assertExitCode(0);

    expect(DB::table('content.item_slugs')->count())->toBe(0);
});

it('re-runs clean — the second pass mints nothing', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    slugItem($pro->id, 'Organ recital');

    $this->artisan('content:backfill-item-slugs')->assertExitCode(0);
    $this->artisan('content:backfill-item-slugs')
        ->expectsOutputToContain('minted 0')
        ->assertExitCode(0);

    expect(DB::table('content.item_slugs')->count())->toBe(1);
});

// An unheadlined item would slug to `item-<hex>` and then SQUAT that name via
// the non-partial unique. Better to leave it for the projection that names it.
it('skips an item with no headline rather than minting item-hex', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    slugItem($pro->id, null);

    $this->artisan('content:backfill-item-slugs')
        ->expectsOutputToContain('minted 0')
        ->assertExitCode(0);

    expect(DB::table('content.item_slugs')->count())->toBe(0);
});

it('leaves non-event kinds alone unless asked for', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    slugItem($pro->id, 'A clip', 'video');

    $this->artisan('content:backfill-item-slugs')->assertExitCode(0);
    expect(DB::table('content.item_slugs')->count())->toBe(0);

    $this->artisan('content:backfill-item-slugs --kind=video')->assertExitCode(0);
    expect(DB::table('content.item_slugs')->count())->toBe(1);
});

it('skips a removed item', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $itemId = slugItem($pro->id, 'Cancelled');
    DB::table('content.items')->where('id', $itemId)->update(['removed_at' => now()]);

    $this->artisan('content:backfill-item-slugs')->assertExitCode(0);

    expect(DB::table('content.item_slugs')->count())->toBe(0);
});

// Review finding: without the collisionSuffix/canonicalSlug no-op check, an
// item holding `grant-writing-2` (because another item took the bare base)
// walks to -3, then -4, on every re-projection — a new row and a NEW PUBLIC
// URL each run. ItemSlugAllocator's docblock warns whoever writes this class.
it('does not rotate the slug of a collided item on repeated calls', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $allocator = app(ContentItemSlugAllocator::class);

    $first = slugItem($pro->id, 'Grant writing');
    $second = slugItem($pro->id, 'Grant writing');

    $allocator->ensureCurrent($pro->id, $first, 'Grant writing');
    expect($allocator->ensureCurrent($pro->id, $second, 'Grant writing'))->toBe('grant-writing-2');

    // Re-run three times, as re-projection would.
    foreach (range(1, 3) as $ignored) {
        expect($allocator->ensureCurrent($pro->id, $second, 'Grant writing'))->toBe('grant-writing-2');
    }

    expect(DB::table('content.item_slugs')->where('item_id', $second)->count())->toBe(1);
});

// The other half of the same trap: digits that came from the NAME must not be
// mistaken for a collision suffix, or a genuine rename reads as a no-op.
it('treats trailing digits in a name as part of the slug, not a suffix', function () {
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $allocator = app(ContentItemSlugAllocator::class);
    $itemId = slugItem($pro->id, 'Table 9');

    expect($allocator->ensureCurrent($pro->id, $itemId, 'Table 9'))->toBe('table-9');
    // 'Table' is a real rename away from 'Table 9' — base 'table' != 'table-9',
    // and -9 is not a suffix `table` would have minted for this item.
    expect($allocator->ensureCurrent($pro->id, $itemId, 'Table'))->toBe('table');
});

// ── #PGR-8: chunkById() replaces cursor(), preload replaces per-item exists() ──

it('mints every item across chunk boundaries, not just the first page', function () {
    config(['partna.content.slug_backfill_chunk' => 2]);
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $ids = [];
    foreach (range(1, 5) as $i) {
        $ids[] = slugItem($pro->id, "Chunk item {$i}");
    }

    $this->artisan('content:backfill-item-slugs')
        ->expectsOutputToContain('minted 5')
        ->assertExitCode(0);

    foreach ($ids as $id) {
        expect(DB::table('content.item_slugs')->where('item_id', $id)->where('is_current', true)->count())->toBe(1);
    }
});

it('the per-chunk preload agrees with a per-item check across a chunk boundary', function () {
    config(['partna.content.slug_backfill_chunk' => 2]);
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    $ids = [];
    foreach (range(1, 5) as $i) {
        $ids[] = slugItem($pro->id, "Preload item {$i}");
    }
    // Item 3 already has a current slug — must be picked up as a skip even
    // though it falls in the middle chunk (rows 3–4 of a chunk-2 walk).
    app(ContentItemSlugAllocator::class)->ensureCurrent($pro->id, $ids[2], 'Preload item 3');

    $this->artisan('content:backfill-item-slugs')
        ->expectsOutputToContain('minted 4, skipped 1')
        ->assertExitCode(0);
});

// NOTE on the item_id-vs-composite question the plan raised: a genuine
// cross-user current row for the SAME item_id cannot be constructed even
// directly via SQL — `idx_content_item_slugs_one_current` (migration
// 20260812040000) is a partial UNIQUE INDEX on item_id alone (not the
// composite), so a second is_current=true row for that item_id is physically
// rejected at insert time regardless of which user owns it. Verified this by
// attempting exactly that insert + a mint against it: the mint does not
// wrongly skip — it attempts ensureCurrent() and gets an insertOrIgnore
// collision failure from that same index, which is the correct outcome for
// an unreachable state and orthogonal to this preload. No test is written
// for it: it would be asserting behaviour on a database state the schema
// itself cannot produce.

// The actual proof of #PGR-8's second half: one content.item_slugs SELECT per
// chunk, not one exists() per item. 5 items at chunk size 2 = 3 chunks = 3
// preload queries. --dry-run keeps the allocator's own item_slugs reads out
// of the count entirely.
it('queries content.item_slugs once per chunk, not once per item', function () {
    config(['partna.content.slug_backfill_chunk' => 2]);
    $pro = createTenant('slug-'.Str::lower(Str::random(6)));
    foreach (range(1, 5) as $i) {
        slugItem($pro->id, "Query count item {$i}");
    }

    $pg = DB::connection('pgsql');
    $pg->flushQueryLog();
    $pg->enableQueryLog();
    $this->artisan('content:backfill-item-slugs --dry-run')->assertExitCode(0);
    $slugSelects = collect($pg->getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'item_slugs') && str_starts_with(trim($q['query']), 'select'));
    $pg->disableQueryLog();

    expect($slugSelects)->toHaveCount(3);
});
