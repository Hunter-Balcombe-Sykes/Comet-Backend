<?php

namespace App\Services\Site;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// Owns site.item_slugs: the per-profile human-readable URL slug registry for
// public sitepage detail items (events + menu items). is_current=false rows are
// retired slugs kept alive as 301 redirect targets.
//
// Collision handling mirrors SiteProvisioningService: insert, catch the unique
// violation, retry with a -N suffix. Uses -2-first (fish-tacos, fish-tacos-2),
// matching the pages-app fallback loop and the user-approved scheme — distinct
// from the subdomain allocator's -1-first convention (left unchanged).
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

        // Same base as the live slug (ignoring any -N suffix) → no-op.
        if ($current !== null && $this->stripSuffix($current->slug) === $base) {
            return $current->slug;
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

    private function insertUnique(string $userId, string $itemType, string $itemKey, string $base, bool $isCurrent): string
    {
        for ($n = 1; $n <= 200; $n++) {
            $slug = $n === 1 ? $base : $base.'-'.$n;
            try {
                DB::connection('pgsql')->table(self::TABLE)->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'item_type' => $itemType,
                    'item_key' => $itemKey,
                    'slug' => $slug,
                    'is_current' => $isCurrent,
                    'created_at' => now()->toDateTimeString(),
                ]);

                return $slug;
            } catch (UniqueConstraintViolationException $e) {
                // Slug taken in this profile (unique_slug) — try the next suffix.
                continue;
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

    private function stripSuffix(string $slug): string
    {
        return preg_replace('/-\d+$/', '', $slug);
    }
}
