<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Services\Platforms\MenuProjectionMapper;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Slice 4 §9: land every live `site.menu_items` row as a `menu_item` content
 * item, through the slice-0b manual lane — never a raw write into `content.*`.
 *
 * Idempotent on the coord, which is menu-scoped and derived from the normalised
 * dish name rather than the row uuid; MenuProjectionMapper's docblock carries
 * the reasoning. A re-run therefore UPDATES every row and mints nothing.
 *
 * The duplicate-name counter is live defence, not dead code. `site.menu_items`
 * has no uniqueness on (menu_id, name), so "Iced Latte" and "ICED  LATTE" are
 * two permitted rows that collapse to ONE coord. Without the dedupe the run
 * would write that coord twice and report two, and the coverage gate — which
 * counts coords, not rows (parent §8.4) — would be unreconcilable.
 */
class MenuBackfiller
{
    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly MenuProjectionMapper $mapper,
    ) {}

    /** @return array{backfilled:int, duplicate_name:int, skipped_no_site:int, skipped_no_name:int, failed:int, slugs_migrated:int, slugs_collided:int, slugs_unmapped:int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = [
            'backfilled' => 0,
            'duplicate_name' => 0,
            'skipped_no_site' => 0,
            'skipped_no_name' => 0,
            'failed' => 0,
            'slugs_migrated' => 0,
            'slugs_collided' => 0,
            'slugs_unmapped' => 0,
        ];
        $touchedSites = [];

        $menus = DB::connection('pgsql')->table('site.menus')
            ->whereNull('deleted_at')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->get();

        foreach ($menus as $menu) {
            $owner = (string) $menu->user_id;

            // §8.2: derive user_id through the site explicitly. site.sites has
            // no deleted_at — sites die by cascade, not soft delete — so a null
            // here means the owner has no site at all, which is a counted skip
            // rather than a write against a tenant that does not exist.
            $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $owner)->value('id');

            $categoriesByDish = $this->categoriesByDish((string) $menu->id);
            $platformsByDish = $this->platformsByDish((string) $menu->id);
            $seen = [];

            $dishes = DB::connection('pgsql')->table('site.menu_items')
                ->where('menu_id', $menu->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($dishes as $dish) {
                try {
                    $name = trim((string) $dish->name);
                    if ($name === '') {
                        // A coord for the empty string would collide every
                        // nameless dish on the menu onto one item.
                        $result['skipped_no_name']++;

                        continue;
                    }

                    $coord = MenuProjectionMapper::coordFor((string) $menu->id, $name);
                    if (isset($seen[$coord])) {
                        $result['duplicate_name']++;

                        continue;
                    }
                    $seen[$coord] = true;

                    if ($siteId === null) {
                        $result['skipped_no_site']++;

                        continue;
                    }

                    if ($dryRun) {
                        $result['backfilled']++;

                        continue;
                    }

                    $this->writer->writeManualItem($owner, $coord, $this->mapper->project(
                        $dish,
                        $categoriesByDish[(string) $dish->id] ?? [],
                        $platformsByDish[(string) $dish->id] ?? [],
                        $menu,
                    ));

                    $touchedSites[(string) $siteId] = true;
                    $result['backfilled']++;
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('Menu backfill failed for one dish.', [
                        'menu_item' => $dish->id,
                        'error' => $e->getMessage(),
                    ]);
                    $result['failed']++;
                }
            }
        }

        // Phase 2, in the same command rather than a second one someone can
        // forget: the mapping is legacy uuid -> coord -> content item, and the
        // middle term does not exist until the items above have landed.
        $result = array_merge($result, $this->migrateSlugs($dryRun, $userId));

        if (! $dryRun && $touchedSites !== []) {
            // All three lanes (§9.2). writeManualItem() bumps build state per
            // item, but the 60s public-profile cache keys off
            // site.sites.updated_at and the edge copy outlives the origin
            // write — neither is covered by a bump.
            SiteCacheLanes::bust(array_keys($touchedSites));
        }

        return $result;
    }

    /**
     * Carry site.item_slugs' menu_item rows onto the content items their dishes
     * became. is_current, retired_at and created_at are preserved verbatim:
     * created_at is what lookupCurrent() orders stranded rows by, and
     * retired_at IS the 301 — dropping it would migrate the live URL and lose
     * every redirect behind it.
     *
     * A (user_id, slug) already held by a DIFFERENT item is counted and
     * skipped, never inserted. idx_item_slugs_unique is NON-partial, so the
     * alternative is a 23505 mid-run with half the permalinks moved.
     *
     * @return array{slugs_migrated:int, slugs_collided:int, slugs_unmapped:int}
     */
    private function migrateSlugs(bool $dryRun, ?string $userId): array
    {
        $out = ['slugs_migrated' => 0, 'slugs_collided' => 0, 'slugs_unmapped' => 0];

        $legacy = DB::connection('pgsql')->table('site.item_slugs')
            ->where('item_type', 'menu_item')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('created_at')
            ->get();

        foreach ($legacy as $row) {
            $itemId = $this->contentItemForLegacyDish((string) $row->user_id, (string) $row->item_key);
            if ($itemId === null) {
                // The dish never landed — it was deleted, nameless, or its
                // owner has no site. Counted so the total reconciles against
                // the 318 rather than quietly shrinking.
                $out['slugs_unmapped']++;

                continue;
            }

            $holder = DB::connection('pgsql')->table('content.item_slugs')
                ->where('user_id', $row->user_id)->where('slug', $row->slug)
                ->value('item_id');

            if ($holder !== null && (string) $holder !== $itemId) {
                $out['slugs_collided']++;

                continue;
            }

            // Already carried — by a previous run, or by the mint that
            // refreshItemCaches() performs for every landed dish.
            if ($holder !== null) {
                $out['slugs_migrated']++;

                continue;
            }

            if (! $dryRun) {
                DB::connection('pgsql')->table('content.item_slugs')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'user_id' => $row->user_id,
                    'item_id' => $itemId,
                    'slug' => $row->slug,
                    'is_current' => (bool) $row->is_current,
                    'created_at' => $row->created_at,
                    'retired_at' => $row->retired_at,
                ]);
            }

            $out['slugs_migrated']++;
        }

        return $out;
    }

    /**
     * legacy dish uuid -> the content item its coord is anchored to, or null.
     *
     * content.item_anchors is the coord->item binding bindGroup() writes.
     * Reading superseded_by FIRST is load-bearing, not defensive: this slice
     * produces the programme's first real merges, and a dish that merged into
     * another must carry its permalink to the SURVIVING item — otherwise the
     * slug points at a row mergeInto() hard-deleted.
     */
    private function contentItemForLegacyDish(string $userId, string $dishId): ?string
    {
        $dish = DB::connection('pgsql')->table('site.menu_items')
            ->where('id', $dishId)->first(['menu_id', 'name']);

        if ($dish === null) {
            return null;
        }

        $anchor = DB::connection('pgsql')->table('content.item_anchors')
            ->where('user_id', $userId)
            ->where('coord', MenuProjectionMapper::coordFor((string) $dish->menu_id, (string) $dish->name))
            ->first(['item_id', 'superseded_by']);

        return $anchor === null ? null : (string) ($anchor->superseded_by ?? $anchor->item_id);
    }

    /**
     * Every category each dish belongs to, in the menu's own category order.
     * A dish spans several categories since 20260721090000, so this is a list
     * per dish, not a lookup.
     *
     * @return array<string, list<array{id: string, name: string, position: int}>>
     */
    private function categoriesByDish(string $menuId): array
    {
        $rows = DB::connection('pgsql')->table('site.menu_item_categories as mic')
            ->join('site.menu_categories as mc', 'mc.id', '=', 'mic.menu_category_id')
            ->where('mc.menu_id', $menuId)
            ->orderBy('mc.position')
            ->get(['mic.menu_item_id', 'mc.id', 'mc.name', 'mc.position']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->menu_item_id][] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'position' => (int) $row->position,
            ];
        }

        return $out;
    }

    /** @return array<string, list<object>> */
    private function platformsByDish(string $menuId): array
    {
        $rows = DB::connection('pgsql')->table('site.menu_item_platforms as mip')
            ->join('site.menu_items as mi', 'mi.id', '=', 'mip.menu_item_id')
            ->where('mi.menu_id', $menuId)
            ->get([
                'mip.menu_item_id', 'mip.platform',
                'mip.pickup_price', 'mip.pickup_url',
                'mip.delivery_price', 'mip.delivery_url',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->menu_item_id][] = $row;
        }

        return $out;
    }
}
