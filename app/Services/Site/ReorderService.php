<?php

namespace App\Services\Site;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reorders a sort_order-ordered list to match a desired id sequence.
 * Uses two-pass renumber (offset then 0..n) to be collision-safe against
 * partial unique indexes on sort_order.
 */
class ReorderService
{
    /**
     * @param string[]     $ids          desired leading order
     * @param Builder      $scopeQuery   WHERE-only builder; cloned internally per use
     * @param string       $lockKey      pg advisory lock key, e.g. "blocks-links:{$site->id}"
     * @param Closure|null $afterCommit  runs after the transaction commits (e.g. fn () => $site->touch())
     */
    public function reorder(array $ids, Builder $scopeQuery, string $lockKey, ?Closure $afterCommit = null): void
    {
        DB::transaction(function () use ($ids, $scopeQuery, $lockKey) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$lockKey]);

            $allIds = (clone $scopeQuery)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);
            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more items are invalid.');
                }
            }

            $newOrder = array_merge($ids, array_values(array_diff($allIds, $ids)));
            $offset = (int) (clone $scopeQuery)->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                (clone $scopeQuery)->where('id', $id)->update(['sort_order' => $offset + $i]);
            }
            foreach ($newOrder as $i => $id) {
                (clone $scopeQuery)->where('id', $id)->update(['sort_order' => $i]);
            }
        });

        if ($afterCommit !== null) {
            $afterCommit();
        }
    }
}
