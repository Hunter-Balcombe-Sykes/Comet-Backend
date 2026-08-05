<?php

namespace App\Site\Pools;

use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Find-or-create the page + section a pool's curation hangs off.
 *
 * On demand rather than at signup: a site that never opens a pool never
 * grows the rows, and an existing site gains them the first time the pool
 * is read — the dashboard GET is enough, no backfill command to schedule.
 * Idempotent under the (site, key) unique indexes; a concurrent first-read
 * race falls back to the row the other request won with.
 */
class PoolSectionProvisioner
{
    /** @return object The site.sections row (raw, as DocumentBuilder reads it). */
    public function ensure(Site $site, string $pool): object
    {
        $sectionKey = PoolRegistry::sectionKey($pool);

        $existing = DB::table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', $sectionKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $pageId = $this->ensurePage($site, $pool);

        try {
            DB::table('site.sections')->insert([
                'id' => (string) Str::uuid(),
                'page_id' => $pageId,
                'site_id' => $site->id,
                'key' => $sectionKey,
                'label' => PoolRegistry::PAGE_LABELS[$pool] ?? ucfirst($pool),
                'slot' => 'body',
                'kind' => 'collection',
                'sort_order' => 0,
                // The pool contract: pins are the hand-picks, the rule is the
                // auto half (each auto-source's newest item), excludes are
                // removals. Mixed mode is exactly that composition.
                'rule' => json_encode(['all' => [
                    ['op' => 'kind_is', 'values' => PoolRegistry::kinds($pool)],
                    ['op' => 'latest_per_auto_source', 'values' => PoolRegistry::kinds($pool)],
                ]]),
                'mode' => 'mixed',
                'order_by' => 'recency',
                'render' => 'cards',
                'min_items' => 1,
                'on_empty' => 'hide',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Lost the first-read race — the winner's row is the section.
        }

        return DB::table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', $sectionKey)
            ->first();
    }

    private function ensurePage(Site $site, string $pool): string
    {
        $pageKey = PoolRegistry::PAGE_KEYS[$pool] ?? $pool;

        $existing = DB::table('site.pages')
            ->where('site_id', $site->id)
            ->where('key', $pageKey)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();

        try {
            DB::table('site.pages')->insert([
                'id' => $id,
                'site_id' => $site->id,
                'key' => $pageKey,
                'label' => PoolRegistry::PAGE_LABELS[$pool] ?? ucfirst($pool),
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Concurrent create — read the winner.
            return (string) DB::table('site.pages')
                ->where('site_id', $site->id)
                ->where('key', $pageKey)
                ->value('id');
        }

        return $id;
    }
}
