<?php

namespace App\Services\Site;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

// Owns site.item_slugs: the per-profile human-readable URL slug registry for
// public sitepage detail items (events + menu items). is_current=false rows are
// retired slugs kept alive as 301 redirect targets.
//
// Collision handling: insert-or-ignore, retry the next -N suffix when the slug
// was already taken (see insertUnique() for why NOT catch-and-retry, which is
// what SiteProvisioningService does). Uses -2-first (fish-tacos, fish-tacos-2),
// matching the pages-app fallback loop and the user-approved scheme — distinct
// from the subdomain allocator's -1-first convention (left unchanged).
//
// Trailing digits in a stored slug are NOT a reliable collision marker: `table-9`
// is what "Table 9" slugifies to at n=1, indistinguishable by shape from the -9
// a collision would have minted. Deciding "is this slug still right?" therefore
// needs the registry, not a regex — see ensureCurrent()/canonicalSlug(). Anyone
// later writing an allocator for `content.item_slugs` (same table shape, no
// allocator today) inherits the same trap.
class ItemSlugAllocator
{
    public const TYPE_EVENT = 'event';

    public const TYPE_MENU_ITEM = 'menu_item';

    private const MAX_BASE = 80;

    private const TABLE = 'site.item_slugs';

    // Ensure the item has a current slug matching $name; returns the current slug.
    public function ensureCurrent(string $userId, string $itemType, string $itemKey, string $name): string
    {
        $base = $this->base($name, $itemKey);
        $current = $this->currentRow($userId, $itemType, $itemKey);

        // No-op iff the live slug is the one allocateSlug() would land on for this
        // base right now. The old test — "strip a trailing -N and compare bases" —
        // could not tell a collision suffix from digits that are part of the name:
        // "Table 9" mints `table-9` at n=1, so renaming it to "Table" looked like a
        // no-op and the item kept serving the stale public slug.
        if ($current !== null) {
            $live = (string) $current->slug;
            if ($live === $base) {
                return $live;
            }
            $n = $this->collisionSuffix($live, $base);
            // Not `base-<N>` at all → genuine rename. A suffix allocateSlug() could
            // never mint (leading zero, < 2, > its 200-iteration ceiling) → the digits
            // came from the name, so re-mint. Both cost zero extra queries.
            if ($n !== null && $this->canonicalSlug($userId, $itemType, $itemKey, $base, $n) === $live) {
                return $live;
            }
        }

        // Rename-back: this item already owns a retired row for this base → reactivate it
        // instead of minting base-3.
        $owned = DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)->where('item_type', $itemType)->where('item_key', $itemKey)
            ->where('slug', $base)->first();
        if ($owned !== null) {
            return $this->promote($userId, $itemType, $itemKey, $base);
        }

        // First mint for this item → insert straight as current.
        if ($current === null) {
            return $this->insertUnique($userId, $itemType, $itemKey, $base, true);
        }

        // Rename → insert the new slug as NON-current first (so the one-current
        // partial index isn't violated by two current rows), then promote it,
        // which demotes the previous current slug to a redirect in one transaction.
        $slug = $this->insertUnique($userId, $itemType, $itemKey, $base, false);

        return $this->promote($userId, $itemType, $itemKey, $slug);
    }

    // Remove every row for an item (menu-item deletion) so its slug frees up.
    public function forget(string $userId, string $itemType, string $itemKey): void
    {
        DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)->where('item_type', $itemType)->where('item_key', $itemKey)
            ->delete();
    }

    /**
     * Batch forget() — one DELETE per 1000 keys instead of one per item, for
     * the wholesale-rebuild callers (MenuFetchJob, the events observer, the
     * backfill prune). Hard-deletes current AND retired rows exactly like
     * forget(): item_slugs_unique_slug is NON-partial, so a retired row still
     * squats the slug and a soft retire would not free it.
     *
     * Chunked at 1000: comfortably inside the bind-parameter ceiling on every
     * driver this actually runs against — SQLite defaults to 32766 (PHP 8.2
     * bundles SQLite >= 3.32, so the old sub-1000 builds don't apply here) and
     * Postgres to 65535 — with headroom to spare for the handful of extra
     * scalar binds (user_id, item_type, and — in ensureCurrentMany()'s
     * prefilter — is_current) each call adds on top of the chunk.
     *
     * Best paired with ensureCurrentMany() OUTSIDE an open transaction — see
     * that method's note.
     *
     * @param  list<string>  $itemKeys
     */
    public function forgetMany(string $userId, string $itemType, array $itemKeys): void
    {
        $keys = array_values(array_unique(array_map('strval', $itemKeys)));
        if ($keys === []) {
            return;
        }

        foreach (array_chunk($keys, 1000) as $chunk) {
            DB::connection('pgsql')->table(self::TABLE)
                ->where('user_id', $userId)->where('item_type', $itemType)
                ->whereIn('item_key', $chunk)
                ->delete();
        }
    }

    /**
     * Batch ensureCurrent() for a whole rebuilt collection: one chunked
     * prefilter SELECT establishes which keys already carry a live slug on the
     * right base, and only the remainder fall through to the per-item
     * ensureCurrent() write path. An unchanged 300-dish menu therefore costs
     * ONE query instead of ~600.
     *
     * PREFER CALLING OUTSIDE AN OPEN TRANSACTION. It is no longer unsafe inside
     * one — insertUnique() allocates with insertOrIgnore behind a savepoint, so
     * a collision can neither raise nor abort a caller's Postgres transaction
     * (SQLSTATE 25P02) — but this walks a whole rebuilt collection, and holding
     * someone else's write transaction open across that many round trips is its
     * own hazard.
     *
     * `is_current` is compared ONLY inside the SQL WHERE, never read back into
     * PHP — Postgres' PDO driver can hand a boolean column back as the strings
     * 't'/'f' rather than true/false (see lookupCurrent()'s note), which would
     * pass against the SQLite mirror's 0/1 integers and misbehave in production.
     *
     * Per-item failures are swallowed: one unallocatable name must not strand
     * the rest of the batch.
     *
     * @param  array<string, string>  $namesByKey  item_key => name
     */
    public function ensureCurrentMany(string $userId, string $itemType, array $namesByKey): void
    {
        if ($namesByKey === []) {
            return;
        }

        /** @var array<string, string> $currentByKey item_key => live slug */
        $currentByKey = [];
        foreach (array_chunk(array_map('strval', array_keys($namesByKey)), 1000) as $chunk) {
            $rows = DB::connection('pgsql')->table(self::TABLE)
                ->where('user_id', $userId)->where('item_type', $itemType)
                ->whereIn('item_key', $chunk)
                ->where('is_current', true)
                ->get(['item_key', 'slug']);
            foreach ($rows as $row) {
                $currentByKey[(string) $row->item_key] = (string) $row->slug;
            }
        }

        foreach ($namesByKey as $itemKey => $name) {
            $itemKey = (string) $itemKey;
            $name = (string) $name;
            $live = $currentByKey[$itemKey] ?? null;

            // EXACT match only. The old lenient skip ("same base ignoring any -N")
            // swallowed the stale-slug case before ensureCurrent could see it, so
            // fixing that guard alone left the whole menu-rebuild path unfixed.
            // Cost of the strictness: two extra queries per *suffixed* item per
            // rebuild — both returning "no change" — which on a 300-dish menu is a
            // handful of items, not 300.
            if ($live !== null && $live === $this->base($name, $itemKey)) {
                continue;
            }

            try {
                $this->ensureCurrent($userId, $itemType, $itemKey, $name);
            } catch (Throwable $e) {
                report($e);
                Log::warning('item-slug batch mint failed', [
                    'item_type' => $itemType,
                    'item_key' => $itemKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Batch read for public API controllers: current slug + 301-redirect
     * aliases (retired slugs + the raw item key) for each requested item, in
     * two queries total — never one per item. Items with no current slug are
     * simply absent from the returned map (the caller's own fallback, e.g.
     * `slug: null, aliases: [id]`, covers that case).
     *
     * `is_current` is only ever compared inside a query-builder WHERE here,
     * never read back and compared in PHP — Postgres' PDO driver can return a
     * boolean column as the strings 't'/'f' rather than true/false, which
     * would silently misbehave against a PHP-side equality check despite
     * passing fine against the SQLite test mirror's 0/1 integers.
     *
     * @param  list<string>  $itemKeys
     * @return array<string, array{slug: string, aliases: list<string>}>
     */
    public function lookupCurrent(string $userId, string $itemType, array $itemKeys): array
    {
        if ($itemKeys === []) {
            return [];
        }

        // A fresh builder per call — each ->where()/->get() chain below starts
        // from this same base filter but must not share one mutated instance.
        $base = fn () => DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('item_type', $itemType)
            ->whereIn('item_key', $itemKeys);

        /** @var array<string, string> $currentByKey item_key => current slug */
        $currentByKey = $base()->where('is_current', true)->pluck('slug', 'item_key')->all();
        if ($currentByKey === []) {
            return [];
        }

        $allRows = $base()->get(['item_key', 'slug']);

        $map = [];
        foreach ($currentByKey as $itemKey => $slug) {
            $retired = $allRows
                ->where('item_key', $itemKey)
                ->pluck('slug')
                ->reject(fn ($s) => $s === $slug)
                ->all();
            $map[$itemKey] = ['slug' => $slug, 'aliases' => [...array_values($retired), $itemKey]];
        }

        return $map;
    }

    private function base(string $name, string $itemKey): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            return 'item-'.substr($itemKey, 0, 6);
        }
        if (strlen($slug) > self::MAX_BASE) {
            // Truncate at a word boundary so a long name can't push the -N off the end.
            $slug = substr($slug, 0, self::MAX_BASE);
            $lastHyphen = strrpos($slug, '-');
            if ($lastHyphen !== false && $lastHyphen > 0) {
                $slug = substr($slug, 0, $lastHyphen);
            }
            $slug = trim($slug, '-');
        }

        return $slug;
    }

    private function currentRow(string $userId, string $itemType, string $itemKey): ?object
    {
        return DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)->where('item_type', $itemType)
            ->where('item_key', $itemKey)->where('is_current', true)->first();
    }

    /**
     * Allocate the first free slug for this item and return it.
     *
     * Runs inside a NESTED transaction whenever the caller already holds one.
     * MenuItemObserver swallows allocator failures by design ("slug bookkeeping
     * must never break a dish write"), but on Postgres a failed statement puts
     * the whole enclosing transaction into an aborted state (SQLSTATE 25P02)
     * where every later statement errors — so a swallow with nothing to roll
     * back to takes the caller's work down with it. A nested transaction
     * compiles to SAVEPOINT / ROLLBACK TO SAVEPOINT on pgsql AND sqlite
     * (Grammar::supportsSavepoints() is true and neither driver grammar
     * overrides it), containing any failure to this insert. At level 0 the
     * insert runs bare — no extra BEGIN/COMMIT round trip for the ordinary
     * caller. SQLite aborts only the statement, so the suite cannot reproduce
     * the production failure; see allocateSlug() for what it can.
     */
    private function insertUnique(string $userId, string $itemType, string $itemKey, string $base, bool $isCurrent): string
    {
        $connection = DB::connection('pgsql');
        $allocate = fn (): string => $this->allocateSlug($userId, $itemType, $itemKey, $base, $isCurrent);

        return $connection->transactionLevel() > 0
            ? $connection->transaction($allocate)
            : $allocate();
    }

    /**
     * Walk `$base`, `$base-2`, `$base-3`… until one lands.
     *
     * insertOrIgnore, NOT insert-and-catch: it compiles to `ON CONFLICT DO
     * NOTHING` on Postgres and `INSERT OR IGNORE` on SQLite, so a slug already
     * taken in this profile comes back as 0 rows affected rather than raising.
     * The expected collision therefore never produces a failed statement at
     * all — which is what the old catch-and-retry got wrong: it worked on
     * SQLite and aborted the caller's transaction on Postgres (see
     * insertUnique()'s note), and MenuScanApplier::apply() wraps the
     * MenuItem::create() that lands here.
     */
    private function allocateSlug(string $userId, string $itemType, string $itemKey, string $base, bool $isCurrent): string
    {
        for ($n = 1; $n <= 200; $n++) {
            $slug = $n === 1 ? $base : $base.'-'.$n;
            $inserted = DB::connection('pgsql')->table(self::TABLE)->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'item_type' => $itemType,
                'item_key' => $itemKey,
                'slug' => $slug,
                'is_current' => $isCurrent,
                'created_at' => now()->toDateTimeString(),
            ]);
            if ($inserted > 0) {
                return $slug;
            }
        }

        throw new RuntimeException("Could not allocate a slug for {$itemType}:{$itemKey}");
    }

    private function promote(string $userId, string $itemType, string $itemKey, string $slug): string
    {
        DB::connection('pgsql')->transaction(function () use ($userId, $itemType, $itemKey, $slug) {
            // Demote first so the one-current partial unique index never sees two current rows.
            $this->demoteExcept($userId, $itemType, $itemKey, $slug);
            DB::connection('pgsql')->table(self::TABLE)
                ->where('user_id', $userId)->where('item_type', $itemType)
                ->where('item_key', $itemKey)->where('slug', $slug)
                ->update(['is_current' => true]);
        });

        return $slug;
    }

    private function demoteExcept(string $userId, string $itemType, string $itemKey, string $keepSlug): void
    {
        DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)->where('item_type', $itemType)
            ->where('item_key', $itemKey)->where('slug', '!=', $keepSlug)
            ->update(['is_current' => false]);
    }

    /**
     * Read `$slug` as `$base-<N>` and return N, or null if it isn't that shape or
     * carries an N that allocateSlug() could never have minted.
     *
     * Rejects a leading zero (`table-09` is name-derived — the loop formats N with
     * no padding) and N < 2 (this allocator is -2-first: n=1 stores the bare base).
     * N > 200 is likewise unmintable, allocateSlug()'s loop stopping there — and
     * rejecting it also bounds canonicalSlug()'s candidate list, so "Table 999999"
     * cannot build a million-element whereIn.
     *
     * Plain string ops, not a preg_quote'd regex: the base is arbitrary
     * slugified text and a hand-built pattern is the easier thing to get wrong.
     */
    private function collisionSuffix(string $slug, string $base): ?int
    {
        $prefix = $base.'-';
        if (! str_starts_with($slug, $prefix)) {
            return null;
        }
        $digits = substr($slug, strlen($prefix));
        if ($digits === '' || ! ctype_digit($digits) || $digits[0] === '0') {
            return null;
        }
        $n = (int) $digits;

        return ($n >= 2 && $n <= 200) ? $n : null;
    }

    /**
     * Which of `$base`, `$base-2` … `$base-$upTo` would allocateSlug() land on for
     * this item right now? Null when every candidate is held by someone else.
     *
     * ONE bounded SELECT (≤200 exact slugs, straight down the (user_id, slug)
     * unique index) replaying allocateSlug()'s walk. Two things it must get right:
     *
     * - Availability is keyed on (user_id, slug) ONLY, because item_slugs_unique_slug
     *   is non-partial and NOT scoped by item_type — events and menu items share one
     *   slug namespace per profile. Adding `where('item_type', …)` here would let a
     *   menu item conclude an event's slug is free, and the authoritative
     *   insertOrIgnore would then disagree with this walk.
     * - Ownership is (user_id, item_type, item_key): a row this very item already
     *   holds — current or retired — does not block it.
     *
     * Advisory only. Nothing downstream trusts the answer: insertUnique()'s
     * insertOrIgnore remains the race-safe allocator of record. And this is a plain
     * SELECT, so it cannot abort a caller's Postgres transaction (25P02) — see
     * insertUnique()'s note and tests/Postgres/ItemSlugAllocatorSavepointTest.php.
     */
    private function canonicalSlug(string $userId, string $itemType, string $itemKey, string $base, int $upTo): ?string
    {
        $candidates = [$base];
        for ($n = 2; $n <= $upTo; $n++) {
            $candidates[] = $base.'-'.$n;
        }

        /** @var array<string, bool> $mine slug => owned by this item */
        $mine = [];
        $rows = DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)
            ->whereIn('slug', $candidates)
            ->get(['slug', 'item_type', 'item_key']);
        foreach ($rows as $row) {
            $mine[(string) $row->slug] = (string) $row->item_type === $itemType
                && (string) $row->item_key === $itemKey;
        }

        foreach ($candidates as $candidate) {
            if (! array_key_exists($candidate, $mine) || $mine[$candidate]) {
                return $candidate;
            }
        }

        // Every candidate is squatted by another item — fall through to the normal
        // mint path so insertUnique() raises its existing RuntimeException.
        return null;
    }
}
