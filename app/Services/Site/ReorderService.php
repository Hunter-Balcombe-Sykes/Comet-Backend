<?php

namespace App\Services\Site;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Reorders a sort_order-ordered list to match a desired id sequence.
 * Uses two-pass renumber (offset then 0..n) to be collision-safe against
 * partial unique indexes on sort_order.
 */
class ReorderService
{
    /**
     * @param  string[]  $ids  desired leading order
     * @param  Builder  $scopeQuery  WHERE-only builder; cloned internally per use
     * @param  string  $lockKey  pg advisory lock key, e.g. "blocks-links:{$site->id}"
     * @param  Closure|null  $afterCommit  runs after the transaction commits (e.g. fn () => $site->touch())
     * @param  int|null  $lockTimeoutMs  bound the advisory-lock wait (see AdvisoryLock); null
     *                                   preserves the old unbounded wait for keys this doesn't apply to
     */
    public function reorder(array $ids, Builder $scopeQuery, string $lockKey, ?Closure $afterCommit = null, ?int $lockTimeoutMs = null): void
    {
        DB::connection('pgsql')->transaction(function () use ($ids, $scopeQuery, $lockKey, $lockTimeoutMs) {
            AdvisoryLock::acquire($lockKey, $lockTimeoutMs);

            $this->renumberLocked($ids, $scopeQuery, $lockKey);
        });

        if ($afterCommit !== null) {
            $afterCommit();
        }
    }

    /**
     * The renumber alone, for a caller that ALREADY holds $lockKey inside its
     * own transaction.
     *
     * Exists so a caller ordering two stores in one request can put both under
     * ONE lock scope instead of two: reorder() above opens its own transaction
     * and takes its own lock, so calling it as the second half of a pair leaves
     * a window between the halves where a competing writer can apply its whole
     * reorder, and the request commits one store's order against the other's.
     * `StaffServiceCategoryManagementController::reorder()` is that caller.
     *
     * The caller owns the lock, the transaction and the timeout ceiling. Do not
     * acquire here — a second `SET LOCAL lock_timeout` would silently re-arm the
     * caller's bound for the rest of its transaction.
     *
     * @param  string[]  $ids  desired leading order
     * @param  Builder  $scopeQuery  WHERE-only builder; cloned internally per use
     * @param  string  $lockKey  only to name the key on timeout — not acquired here
     */
    public function renumberLocked(array $ids, Builder $scopeQuery, string $lockKey): void
    {
        // Whole-branch review fix: AdvisoryLock::acquire()'s `SET LOCAL
        // lock_timeout` is scoped to the REST of the caller's transaction
        // (Postgres, not just the one statement) — so when a bound was passed,
        // this row lock aborts at that SAME ceiling (5s for the services key)
        // instead of DatabaseServiceProvider's 10s session-level default. It
        // fails with the identical SQLSTATE (55P03 lock_not_available) as the
        // advisory-lock timeout, so reuse AdvisoryLock's own detection rather
        // than duplicating it, and reuse AdvisoryLockTimeoutException too —
        // every caller (UserServiceController::reorder(),
        // StaffServiceManagementController, and now
        // StaffServiceCategoryManagementController) already catches that type
        // and returns 423. Left uncaught, this was a raw QueryException
        // nothing matched, surfacing as a 500.
        try {
            $allIds = (clone $scopeQuery)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();
        } catch (QueryException $e) {
            if (AdvisoryLock::isLockTimeout($e)) {
                throw new AdvisoryLockTimeoutException($lockKey, $e);
            }

            throw $e;
        }

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
    }
}
