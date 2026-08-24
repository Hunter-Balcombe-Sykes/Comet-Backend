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
    /**
     * ensure() for a set of pools with ONE existence query (2026-08-24, the
     * actions-endpoint batching): the steady state — every section already
     * provisioned — reads them all in a single round trip; only a pool whose
     * row is genuinely missing takes the per-pool find-or-create slow path.
     *
     * @param  list<string>  $pools
     * @return array<string, object> keyed by pool
     */
    public function ensureMany(Site $site, array $pools): array
    {
        $keyToPool = [];
        foreach ($pools as $pool) {
            $keyToPool[PoolRegistry::sectionKey($pool)] = $pool;
        }

        $rows = DB::connection('pgsql')->table('site.sections')
            ->where('site_id', $site->id)
            ->whereIn('key', array_keys($keyToPool))
            ->get()
            ->keyBy('key');

        $out = [];
        foreach ($pools as $pool) {
            $row = $rows->get(PoolRegistry::sectionKey($pool));
            $out[$pool] = $row ?? $this->ensure($site, $pool);
        }

        return $out;
    }

    /** @return object The site.sections row (raw, as DocumentBuilder reads it). */
    public function ensure(Site $site, string $pool): object
    {
        $sectionKey = PoolRegistry::sectionKey($pool);

        $existing = DB::connection('pgsql')->table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', $sectionKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $pageId = $this->ensurePage($site, $pool);
        $shape = PoolRegistry::sectionShape($pool);

        try {
            DB::connection('pgsql')->table('site.sections')->insert([
                'id' => (string) Str::uuid(),
                'page_id' => $pageId,
                'site_id' => $site->id,
                'key' => $sectionKey,
                'label' => PoolRegistry::PAGE_LABELS[$pool] ?? ucfirst($pool),
                'slot' => 'body',
                'kind' => 'collection',
                'sort_order' => 0,
                // The pool contract: pins are the hand-picks, the rule is the
                // auto half, excludes are removals. Mixed mode is exactly that
                // composition. WHICH rule is the pool's own business — see
                // PoolRegistry::sectionShape().
                'rule' => json_encode(['all' => $shape['rule']]),
                'mode' => 'mixed',
                'order_by' => $shape['order_by'],
                'render' => 'cards',
                'min_items' => 1,
                'on_empty' => 'hide',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Lost the first-read race — the winner's row is the section.
        }

        return DB::connection('pgsql')->table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', $sectionKey)
            ->first();
    }

    private function ensurePage(Site $site, string $pool): string
    {
        $pageKey = PoolRegistry::PAGE_KEYS[$pool] ?? $pool;

        $existing = DB::connection('pgsql')->table('site.pages')
            ->where('site_id', $site->id)
            ->where('key', $pageKey)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();

        try {
            DB::connection('pgsql')->table('site.pages')->insert([
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
            return (string) DB::connection('pgsql')->table('site.pages')
                ->where('site_id', $site->id)
                ->where('key', $pageKey)
                ->value('id');
        }

        return $id;
    }
}
