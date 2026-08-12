<?php

namespace App\Services\Content;

use App\Services\Site\ItemSlugAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns `content.item_slugs`: the per-profile human-readable URL slug registry
 * for content ITEMS, the pool lane's counterpart to
 * {@see ItemSlugAllocator} (which owns `site.item_slugs`
 * for the legacy events/menu lanes).
 *
 * Deliberately a second class rather than a generalisation of that one: the
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
    private const MAX_BASE = 80;

    private const TABLE = 'content.item_slugs';

    /** Ensure the item has a current slug matching $name; returns that slug. */
    public function ensureCurrent(string $userId, string $itemId, string $name): string
    {
        $base = $this->base($name, $itemId);
        $current = $this->currentRow($userId, $itemId);

        if ($current !== null && (string) $current->slug === $base) {
            return $base;
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
