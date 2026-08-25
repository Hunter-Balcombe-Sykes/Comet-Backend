<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns `content.item_slugs`: the per-profile human-readable URL slug registry
 * for content ITEMS. It superseded the legacy `ItemSlugAllocator`
 * (`site.item_slugs`, the events/menu lanes), retired in slice 7 Phase 6 once
 * its last reader had moved here.
 *
 * It was deliberately a second class rather than a generalisation of that one: the
 * two tables key differently and cannot share a query. `site.item_slugs` is
 * keyed `(item_type, item_key)` where item_key is a payload hex id;
 * `content.item_slugs` is keyed `item_id` — a real FK to `content.items`.
 * That is exactly why the legacy events slugs do not join to content items by
 * key and had to be mapped through the event URL (slice 2, Task 9 Step 1).
 *
 * Two schema differences from the site table that the code must make up for:
 *
 *  1. There is NO `one_current` partial unique index here. Two `is_current`
 *     rows for one item are physically insertable, so {@see promote()} demotes
 *     in the same transaction as it promotes, and nothing else ever writes
 *     `is_current = true`.
 *  2. `retired_at` was added by 20260731210000 with no backfill, so a
 *     pre-existing retired row can carry `is_current = false` AND
 *     `retired_at IS NULL`. {@see lookupCurrent()} serves those off
 *     `created_at`, matching how site.item_slugs handles its stranded rows.
 *
 * `idx_item_slugs_unique (user_id, slug)` is NON-partial, so a retired slug
 * still squats its name — which is the point: an old URL must keep resolving
 * to a 301 rather than being handed to a different item.
 */
class ContentItemSlugAllocator
{
    /**
     * The kinds that get a public URL slug minted for them.
     *
     * Events and menu items — exactly the pair site.item_slugs covered. Menus
     * joined 2026-08-15 (slice 4), and this const IS the re-homing of slug
     * allocation off MenuItemObserver: ProjectionWriter::refreshItemCaches()
     * mints for every kind in this list, so a landed dish gets its permalink
     * with no observer and no second call site. Every pool item's payload
     * carries the `slug` key regardless, null off this list — the wire shape
     * does not vary by kind.
     *
     * The projector and the backfill BOTH read this, so widening it is one
     * edit and the two cannot disagree about which items should have slugs.
     *
     * @var list<string>
     */
    public const SLUGGED_KINDS = ['event', 'menu_item'];

    private const MAX_BASE = 80;

    private const TABLE = 'content.item_slugs';

    /** Ensure the item has a current slug matching $name; returns that slug. */
    public function ensureCurrent(string $userId, string $itemId, string $name): string
    {
        $base = $this->base($name, $itemId);
        $current = $this->currentRow($userId, $itemId);

        if ($current !== null) {
            $live = (string) $current->slug;
            if ($live === $base) {
                return $base;
            }

            // The live slug may be `base-N` because the bare base was taken by
            // another item — that is still the right slug for this name, not a
            // rename. Without this arm, every re-projection of a collided item
            // walks to the next free suffix and retires the last one: an item
            // holding `grant-writing-2` re-mints `-3`, then `-4`, growing a row
            // per run and changing the public URL each time. ItemSlugAllocator
            // learned this the hard way and its docblock warns whoever writes
            // this class; both checks cost zero extra queries in the no-op case.
            $n = $this->collisionSuffix($live, $base);
            if ($n !== null && $this->canonicalSlug($userId, $itemId, $base, $n) === $live) {
                return $live;
            }
        }

        // Rename-back: this item already owns a retired row for this base, so
        // reactivate it rather than minting `base-3` and stranding the name.
        $owned = DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)->where('item_id', $itemId)->where('slug', $base)
            ->exists();

        if ($owned) {
            return $this->promote($userId, $itemId, $base);
        }

        // Insert NON-current first, then promote — the demotion of the old
        // current row and the promotion of the new one share one transaction,
        // because no partial index would catch a moment with two currents.
        $slug = $this->allocate($userId, $itemId, $base, $current === null);

        return $current === null ? $slug : $this->promote($userId, $itemId, $slug);
    }

    /**
     * Current slug + the retired slugs that should still 301 to it, keyed by
     * item id. Mirrors ItemSlugAllocator::lookupCurrent()'s contract so the
     * two lanes serialise identically on the wire.
     *
     * The raw item id is always the last alias: a link that predates slugs at
     * all must keep working.
     *
     * @param  list<string>  $itemIds
     * @return array<string, array{slug: string, aliases: list<string>}>
     */
    public function lookupCurrent(string $userId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $base = fn () => DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)
            ->whereIn('item_id', $itemIds);

        /** @var array<string, string> $currentById */
        $currentById = $base()->where('is_current', true)->pluck('slug', 'item_id')->all();
        if ($currentById === []) {
            return [];
        }

        // The active-alias window, same two arms as site.item_slugs: a
        // properly-retired row serves while retired_at is inside the window,
        // and a STRANDED row (is_current=false, retired_at NULL — the shape
        // 20260731210000 left behind by not backfilling) serves off created_at.
        // Without the second arm a stranded row would 301 forever.
        $cutoff = now()->subDays((int) config('partna.item_slugs.retirement_days', 90));
        $rows = $base()
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($q2) use ($cutoff) {
                    $q2->whereNull('retired_at')->where('created_at', '>', $cutoff);
                })->orWhere('retired_at', '>', $cutoff);
            })
            ->get(['item_id', 'slug']);

        $map = [];
        foreach ($currentById as $itemId => $slug) {
            $retired = $rows
                ->where('item_id', $itemId)
                ->pluck('slug')
                ->reject(fn ($s) => $s === $slug)
                ->all();
            $map[(string) $itemId] = [
                'slug' => (string) $slug,
                'aliases' => [...array_values($retired), (string) $itemId],
            ];
        }

        return $map;
    }

    /** Drop every row for an item so its slugs free up (hard delete — the unique is non-partial). */
    public function forget(string $userId, string $itemId): void
    {
        DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)->where('item_id', $itemId)
            ->delete();
    }

    /**
     * The LIVE slug per item for a whole batch (#SCALE-9/#API-7).
     *
     * ensureCurrent() short-circuits when the live slug already equals the
     * base, but still pays one currentRow() SELECT to discover that — inside
     * refreshItemCaches()'s per-item loop that was one round trip per item on
     * every projection run. This answers it once for the chunk.
     *
     * `is_current = true`, NOT `retired_at IS NULL`: 20260731210000 added
     * retired_at without a backfill, so a stranded row carries is_current =
     * false AND retired_at NULL. See this class's header note 2.
     *
     * Leaner than lookupCurrent() on purpose — that one also assembles the
     * 301 alias window, which a refresh pass has no use for.
     *
     * @param  list<string>  $itemIds
     * @return array<string, string> item id => live slug
     */
    public function currentSlugs(string $userId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        /** @var array<string, string> */
        return DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)
            ->whereIn('item_id', $itemIds)
            ->where('is_current', true)
            ->pluck('slug', 'item_id')
            ->all();
    }

    /**
     * The slug this name would take before collision suffixing.
     *
     * Public only so a batch caller can skip the guaranteed no-op case without
     * reimplementing what a slug is — this class stays the one owner of that.
     */
    public function baseSlug(string $name, string $itemId): string
    {
        return $this->base($name, $itemId);
    }

    private function base(string $name, string $itemId): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            return 'item-'.substr($itemId, 0, 6);
        }
        if (strlen($slug) > self::MAX_BASE) {
            // Truncate on a word boundary so a long headline cannot push the
            // collision suffix off the end and collide with its own neighbour.
            $slug = substr($slug, 0, self::MAX_BASE);
            $lastHyphen = strrpos($slug, '-');
            if ($lastHyphen !== false && $lastHyphen > 0) {
                $slug = substr($slug, 0, $lastHyphen);
            }
            $slug = trim($slug, '-');
        }

        return $slug;
    }

    /**
     * The N in `$base-N`, or null when the trailing digits are not a suffix
     * allocate() could ever have minted.
     *
     * Trailing digits are NOT a reliable collision marker on their own:
     * "Table 9" slugifies to `table-9` at n=1, which is shape-identical to the
     * -9 a collision would mint. Excluding leading zeros, <2 and >200 is what
     * separates "the allocator put this here" from "the name ends in a number".
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
     * Which of `$base`, `$base-2` … `$base-$upTo` would allocate() land on for
     * this item right now? Null when every candidate is held by someone else.
     *
     * ONE bounded SELECT replaying allocate()'s walk. Availability is keyed on
     * (user_id, slug) because idx_item_slugs_unique is non-partial; ownership
     * is (user_id, item_id), so a row this item already holds — current or
     * retired — does not block it.
     *
     * Advisory only: insertOrIgnore stays the race-safe allocator of record.
     * A plain SELECT, so it cannot abort a caller's transaction (25P02).
     */
    private function canonicalSlug(string $userId, string $itemId, string $base, int $upTo): ?string
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
            ->get(['slug', 'item_id']);
        foreach ($rows as $row) {
            $mine[(string) $row->slug] = (string) $row->item_id === $itemId;
        }

        foreach ($candidates as $candidate) {
            if (! array_key_exists($candidate, $mine) || $mine[$candidate]) {
                return $candidate;
            }
        }

        return null;
    }

    private function currentRow(string $userId, string $itemId): ?object
    {
        return DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)->where('item_id', $itemId)->where('is_current', true)
            ->first();
    }

    /**
     * Walk `$base`, `$base-2`, `$base-3`… until one lands.
     *
     * insertOrIgnore, NOT insert-and-catch: it compiles to `ON CONFLICT DO
     * NOTHING` / `INSERT OR IGNORE`, so the EXPECTED collision returns 0 rows
     * rather than raising. A raised unique violation would abort the caller's
     * enclosing Postgres transaction (25P02) even though the caller means to
     * carry on — the trap ItemSlugAllocator documents at length.
     */
    private function allocate(string $userId, string $itemId, string $base, bool $isCurrent): string
    {
        for ($n = 1; $n <= 200; $n++) {
            $slug = $n === 1 ? $base : $base.'-'.$n;

            $inserted = DB::connection('pgsql')->table(self::TABLE)->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'item_id' => $itemId,
                'slug' => $slug,
                'is_current' => $isCurrent,
                'created_at' => now(),
            ]);

            if ($inserted > 0) {
                return $slug;
            }
        }

        // 200 collisions on one base within one profile is not a slug problem.
        throw new \RuntimeException("Could not allocate a content item slug for base '{$base}'.");
    }

    /** Make $slug the item's current one and demote whatever held it, atomically. */
    private function promote(string $userId, string $itemId, string $slug): string
    {
        DB::connection('pgsql')->transaction(function () use ($userId, $itemId, $slug) {
            $scope = fn () => DB::connection('pgsql')->table(self::TABLE)
                ->where('user_id', $userId)->where('item_id', $itemId);

            // Demote first: with no one_current partial index, promoting first
            // would leave two current rows visible to a concurrent reader.
            $scope()->where('is_current', true)->where('slug', '!=', $slug)
                ->update(['is_current' => false, 'retired_at' => now()]);

            $scope()->where('slug', $slug)
                ->update(['is_current' => true, 'retired_at' => null]);
        });

        return $slug;
    }
}
