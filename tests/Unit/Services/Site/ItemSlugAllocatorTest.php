<?php

use App\Services\Site\ItemSlugAllocator;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupItemSlugsTable();
    $this->alloc = new ItemSlugAllocator;
    $this->u = 'user-1';
});

function currentSlug(string $user, string $type, string $key): ?string
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user)->where('item_type', $type)
        ->where('item_key', $key)->where('is_current', 1)->value('slug');
}

/**
 * The INSERT/UPDATE/DELETE statements in a captured query log. The no-op guard is
 * allowed to SELECT (it consults the registry rather than pattern-matching the
 * slug); what it must never do is write.
 *
 * @param  list<array{query: string}>  $queries
 * @return list<string>
 */
function writesIn(array $queries): array
{
    return array_values(array_map(
        fn ($q) => $q['query'],
        array_filter($queries, fn ($q) => preg_match('~^\s*(insert|update|delete)~i', $q['query']) === 1)
    ));
}

it('mints a bare slug from the name', function () {
    $slug = $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Margherita Pizza');
    expect($slug)->toBe('margherita-pizza');
});

it('suffixes collisions -2, -3 within a profile', function () {
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos'))->toBe('fish-tacos');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k3', 'Fish Tacos'))->toBe('fish-tacos-3');
});

it('is idempotent for the same item + name', function () {
    $a = $this->alloc->ensureCurrent($this->u, 'event', 'hex1', 'Trivia Night');
    $b = $this->alloc->ensureCurrent($this->u, 'event', 'hex1', 'Trivia Night');
    expect($a)->toBe('trivia-night')->and($b)->toBe('trivia-night');
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(1);
});

it('renames: new current slug, old kept as redirect', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');
    $new = $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'New Name');
    expect($new)->toBe('new-name');
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('new-name');
    $rows = DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_key', 'k1')->pluck('is_current', 'slug');
    expect((int) $rows['old-name'])->toBe(0)->and((int) $rows['new-name'])->toBe(1);
});

it('no-ops a cosmetic edit that slugs the same', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos!!!');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(1);
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('fish-tacos');
});

it('reactivates a prior slug on rename-back instead of spawning -3', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Alpha');   // alpha
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Beta');    // beta (alpha retired)
    $back = $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Alpha');
    expect($back)->toBe('alpha');
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('alpha');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(2);
});

it('falls back to item-<key> for an unsluggable name', function () {
    $slug = $this->alloc->ensureCurrent($this->u, 'menu_item', 'abcdef123456', '🍕🍕');
    expect($slug)->toBe('item-abcdef');
});

it('isolates slugs per user (no cross-profile collision)', function () {
    expect($this->alloc->ensureCurrent('user-1', 'menu_item', 'k1', 'Opening Night'))->toBe('opening-night');
    expect($this->alloc->ensureCurrent('user-2', 'menu_item', 'k9', 'Opening Night'))->toBe('opening-night');
});

it('forget() removes rows and frees the slug for reuse', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->forget($this->u, 'menu_item', 'k1');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(0);
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos');
});

// ── allocating inside a caller's open transaction ──
// insertUnique() used to INSERT-and-catch the unique violation with no
// savepoint. On Postgres a failed statement aborts the WHOLE enclosing
// transaction (SQLSTATE 25P02) and every later statement errors, so a
// collision under MenuScanApplier::apply()'s transaction rolled the entire
// scan enrichment back. SQLite aborts only the statement, so NONE of the
// tests below can reproduce that failure — what they pin is that the two
// defences are actually engaged and that the allocation semantics survive
// them.

it('allocates with the ignore-on-conflict insert, so a taken slug never raises', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');

    DB::enableQueryLog();
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Two attempts — `fish-tacos` (rejected) then `fish-tacos-2` — and BOTH are
    // the ignore-on-conflict form: `insert or ignore` under SQLiteGrammar,
    // `insert ... on conflict do nothing` under PostgresGrammar. A taken slug
    // comes back as 0 rows affected instead of a failed statement, which is the
    // only reason the retry loop is safe inside someone else's transaction.
    $inserts = array_values(array_filter($queries, fn ($q) => stripos($q['query'], 'insert') === 0));
    expect($inserts)->toHaveCount(2);
    foreach ($inserts as $q) {
        expect(strtolower($q['query']))->toContain('or ignore');
    }
});

it('opens a savepoint around the allocation when the caller already holds a transaction', function () {
    $levels = [];
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $e) use (&$levels) {
        $levels[] = $e->connection->transactionLevel();
    });

    DB::connection('pgsql')->transaction(function () {
        expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos'))->toBe('fish-tacos');
    });

    // 1 = the caller's own BEGIN; 2 = the allocator's nested transaction, which
    // Laravel compiles to `SAVEPOINT trans2` (Grammar::supportsSavepoints() is
    // true and neither the SQLite nor the Postgres grammar overrides it), so an
    // unexpected failure — an FK or the item_type CHECK — unwinds to there
    // instead of poisoning the caller.
    expect($levels)->toBe([1, 2]);
    expect(DB::connection('pgsql')->transactionLevel())->toBe(0);
});

it('opens no transaction of its own when the caller has none', function () {
    $began = 0;
    Event::listen(TransactionBeginning::class, function () use (&$began) {
        $began++;
    });

    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos'))->toBe('fish-tacos');

    // The savepoint is conditional: a first mint outside a transaction still
    // costs exactly one INSERT, no BEGIN/COMMIT round trip.
    expect($began)->toBe(0);
});

it('resolves a collision inside the caller transaction and lets it commit', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');

    $slug = DB::connection('pgsql')->transaction(function () {
        $slug = $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos');
        // A write AFTER the colliding allocation — the shape the production
        // bug took (25P02 fails every later statement in the transaction).
        DB::connection('pgsql')->table('site.item_slugs')
            ->where('item_key', 'k2')->update(['created_at' => '2026-07-24 00:00:00']);

        return $slug;
    });

    expect($slug)->toBe('fish-tacos-2');
    expect(currentSlug($this->u, 'menu_item', 'k2'))->toBe('fish-tacos-2');
    // The post-collision write committed with the rest of the transaction.
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k2')->value('created_at'))
        ->toBe('2026-07-24 00:00:00');
    expect(DB::connection('pgsql')->transactionLevel())->toBe(0);
});

it('keeps the rename path (insert-then-promote) correct inside a caller transaction', function () {
    // The rename branch inserts NON-current then promotes, and promote() opens
    // its own nested transaction — three levels deep under a caller's. Pins
    // that nesting the allocation didn't disturb the redirect bookkeeping.
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');

    DB::connection('pgsql')->transaction(function () {
        expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'New Name'))->toBe('new-name');
    });

    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('new-name');
    $rows = DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->pluck('is_current', 'slug');
    expect((int) $rows['old-name'])->toBe(0)->and((int) $rows['new-name'])->toBe(1);
});

// ── lookupCurrent(): shared batch read for public API controllers ──

it('lookupCurrent returns slug + aliases (retired slugs + raw key) for each requested item', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'New Name');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Untouched');

    $map = $this->alloc->lookupCurrent($this->u, 'menu_item', ['k1', 'k2', 'k-missing']);

    expect($map['k1']['slug'])->toBe('new-name');
    expect($map['k1']['aliases'])->toEqualCanonicalizing(['old-name', 'k1']);
    expect($map['k2']['slug'])->toBe('untouched');
    expect($map['k2']['aliases'])->toBe(['k2']);
    expect($map)->not->toHaveKey('k-missing');
});

it('lookupCurrent scopes to the given item_type and user', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'A Dish');
    $this->alloc->ensureCurrent($this->u, 'event', 'k1', 'Same Key Different Type');
    $this->alloc->ensureCurrent('user-2', 'menu_item', 'k1', 'Someone Elses Dish');

    $map = $this->alloc->lookupCurrent($this->u, 'menu_item', ['k1']);

    expect($map['k1']['slug'])->toBe('a-dish');
});

it('lookupCurrent returns an empty map for an empty key list', function () {
    expect($this->alloc->lookupCurrent($this->u, 'menu_item', []))->toBe([]);
});

// ── batch helpers (271-DINT-1): one query for a whole rebuilt collection ──

it('forgetMany() hard-deletes every row for each key and frees the slugs', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'New Name'); // k1 now has a retired row too
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k3', 'Kept Dish');

    $this->alloc->forgetMany($this->u, 'menu_item', ['k1', 'k2']);

    // Retired rows go too — the (user_id, slug) unique index is NOT partial, so
    // leaving one behind would keep the slug squatted.
    expect(DB::connection('pgsql')->table('site.item_slugs')->whereIn('item_key', ['k1', 'k2'])->count())->toBe(0);
    expect(currentSlug($this->u, 'menu_item', 'k3'))->toBe('kept-dish');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k9', 'Fish Tacos'))->toBe('fish-tacos');
});

it('forgetMany() scopes to the user and item type, and no-ops on an empty list', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'A Dish');
    $this->alloc->ensureCurrent($this->u, 'event', 'k1', 'An Event');
    $this->alloc->ensureCurrent('user-2', 'menu_item', 'k1', 'Someone Elses Dish');

    $this->alloc->forgetMany($this->u, 'menu_item', []);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(3);

    $this->alloc->forgetMany($this->u, 'menu_item', ['k1']);
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBeNull();
    expect(currentSlug($this->u, 'event', 'k1'))->toBe('an-event');
    expect(currentSlug('user-2', 'menu_item', 'k1'))->toBe('someone-elses-dish');
});

it('forgetMany() chunks past the bind-parameter ceiling', function () {
    // 2500 keys → 3 chunks of ≤1000 binds. A single whereIn would exceed
    // SQLite's 999-bind limit on older builds and is uncomfortably close to
    // Postgres' 65535 for a large profile.
    $keys = [];
    foreach (range(1, 2500) as $n) {
        $keys[] = "k{$n}";
        DB::connection('pgsql')->table('site.item_slugs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->u, 'item_type' => 'menu_item', 'item_key' => "k{$n}",
            'slug' => "dish-{$n}", 'is_current' => 1, 'created_at' => now()->toDateTimeString(),
        ]);
    }
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2500);

    $this->alloc->forgetMany($this->u, 'menu_item', $keys);

    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(0);
});

it('ensureCurrentMany() mints missing slugs and writes nothing for unchanged ones', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Beef Tacos');
    $before = DB::connection('pgsql')->table('site.item_slugs')->orderBy('item_key')->get()->toArray();

    DB::enableQueryLog();
    $this->alloc->ensureCurrentMany($this->u, 'menu_item', [
        'k1' => 'Fish Tacos',      // unchanged
        'k2' => 'Beef Tacos!!!',   // cosmetic edit — same base, still a no-op
        'k3' => 'Pork Tacos',      // new
    ]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(currentSlug($this->u, 'menu_item', 'k3'))->toBe('pork-tacos');
    // k1/k2 rows are byte-identical — no delete-and-remint, no is_current churn.
    $after = DB::connection('pgsql')->table('site.item_slugs')->whereIn('item_key', ['k1', 'k2'])->orderBy('item_key')->get()->toArray();
    expect($after)->toEqual($before);
    // Zero writes for the two no-op keys: the only INSERT is k3's.
    $writes = array_values(array_filter($queries, fn ($q) => preg_match('~^\s*(insert|update|delete)~i', $q['query']) === 1));
    expect($writes)->toHaveCount(1);
});

it('ensureCurrentMany() keeps a live -N suffix instead of re-minting the bare base', function () {
    // Two dishes sharing a base: k2 legitimately lives on fish-tacos-2.
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');

    DB::enableQueryLog();
    $this->alloc->ensureCurrentMany($this->u, 'menu_item', ['k1' => 'Fish Tacos', 'k2' => 'Fish Tacos']);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('fish-tacos');
    expect(currentSlug($this->u, 'menu_item', 'k2'))->toBe('fish-tacos-2');
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2);
    // k2 no longer short-circuits in the prefilter (it isn't on the bare base), so
    // it pays a couple of extra SELECTs — but it must still write NOTHING.
    expect(writesIn($queries))->toBe([]);
});

it('ensureCurrentMany() re-slugs a renamed item and keeps the old slug as a redirect', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');

    $this->alloc->ensureCurrentMany($this->u, 'menu_item', ['k1' => 'New Name']);

    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('new-name');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(2);
});

it('ensureCurrentMany() no-ops on an empty map', function () {
    $this->alloc->ensureCurrentMany($this->u, 'menu_item', []);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(0);
});

// ── 271-SEM-1: name digits are not a collision suffix ─────────────────
// The no-op guard used to strip a trailing `-N` and compare bases, which cannot
// distinguish `table-9` minted for "Table 9" from the `-9` a collision would
// have produced. The guard now asks the registry which slug allocateSlug() would
// land on for the current base, so "is this still right?" is answered by data.

it('re-slugs an item whose name digits looked like a collision suffix', function () {
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Table 9'))->toBe('table-9');

    // Before the fix this returned `table-9` and the item served a stale URL forever.
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Table'))->toBe('table');
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('table');

    // The old slug survives as a retired row and is published as a redirect source.
    $rows = DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->pluck('is_current', 'slug');
    expect((int) $rows['table-9'])->toBe(0)->and((int) $rows['table'])->toBe(1);
    expect($this->alloc->lookupCurrent($this->u, 'menu_item', ['k1'])['k1']['aliases'])
        ->toEqualCanonicalizing(['table-9', 'k1']);
});

it('keeps a genuine collision suffix as a no-op, writing nothing', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');

    DB::enableQueryLog();
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(writesIn($queries))->toBe([]);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2);
});

it('does not churn a suffixed slug across repeated unchanged saves', function () {
    // An "exact match only" guard would mint -3, -4, -5 here — and EventSlugSync
    // calls ensureCurrent for every event on every connection save.
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos');

    foreach (range(1, 3) as $ignored) {
        expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');
    }

    expect(currentSlug($this->u, 'menu_item', 'k2'))->toBe('fish-tacos-2');
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2);
});

it('keeps a suffixed slug when the sibling holding the base is only retired', function () {
    // item_slugs_unique_slug is NON-partial, so k1's retired `fish-tacos` still
    // squats the base and k2 must stay on -2.
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Beef Tacos');

    DB::enableQueryLog();
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(writesIn($queries))->toBe([]);
});

it('treats the slug namespace as cross-type when deciding a suffix is still right', function () {
    // item_slugs_unique_slug is (user_id, slug) — NOT scoped by item_type. If the
    // availability walk added `where item_type = ...` the menu item would think
    // `fish-tacos` was free and re-mint itself onto -3.
    expect($this->alloc->ensureCurrent($this->u, 'event', 'e1', 'Fish Tacos'))->toBe('fish-tacos');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos'))->toBe('fish-tacos-2');

    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos'))->toBe('fish-tacos-2');
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2);
});

it('re-slugs to the first free suffix when the bare base is taken by a sibling', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Table');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Table 9'))->toBe('table-9');

    // Not `table-9` (stale) and not `table` (k1 has it) — the next free suffix.
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Table'))->toBe('table-2');
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('table');
});

it('re-slugs past suffix shapes allocateSlug could never have minted', function () {
    // Leading zero: the -N loop formats N unpadded, so `-09` is name-derived.
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Table 09'))->toBe('table-09');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Table'))->toBe('table');

    // Past allocateSlug()'s 200-iteration ceiling — also what bounds the walk's
    // candidate list, so a name like "Table 999999" can't build a huge whereIn.
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Table 500'))->toBe('table-500');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Table'))->toBe('table-2');
});

it('ensureCurrentMany() re-slugs a stale name-digit slug on the rebuild path', function () {
    // The batch prefilter used to skip this key before ensureCurrent ever ran, so
    // fixing only the single-item guard left every menu rebuild unfixed.
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Table 9');

    $this->alloc->ensureCurrentMany($this->u, 'menu_item', ['k1' => 'Table']);

    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('table');
});

it('normalises a suffixed slug back to the base once the colliding sibling is deleted', function () {
    // DELIBERATE behaviour change: with k1 gone the base is genuinely free, so the
    // walk says `fish-tacos` and k2 moves onto it. The -2 stays as a redirect, so
    // no public URL breaks. Pinned here so it stays a decision, not a surprise.
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos');

    $this->alloc->forget($this->u, 'menu_item', 'k1');

    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos');
    $rows = DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k2')->pluck('is_current', 'slug');
    expect((int) $rows['fish-tacos'])->toBe(1)->and((int) $rows['fish-tacos-2'])->toBe(0);
});
