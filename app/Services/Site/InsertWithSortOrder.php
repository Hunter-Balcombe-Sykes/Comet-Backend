<?php

namespace App\Services\Site;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Advisory-lock + next sort_order computation + create, atomically.
 * Standardizes the null→0, non-null→max+1 initialisation across all callers.
 */
class InsertWithSortOrder
{
    /**
     * @param  Builder  $maxQuery  WHERE-only builder scoping the max(sort_order) lookup
     * @param  string  $lockKey  advisory lock key, e.g. "services:{$pro->id}"
     * @param  Closure  $create  fn (int $nextSortOrder): Model — builds and persists the row
     * @param  int|null  $lockTimeoutMs  bound the advisory-lock wait (see AdvisoryLock); null
     *                                   preserves the old unbounded wait for keys this doesn't apply to
     */
    public static function run(Builder $maxQuery, string $lockKey, Closure $create, ?int $lockTimeoutMs = null): Model
    {
        return DB::connection('pgsql')->transaction(function () use ($maxQuery, $lockKey, $create, $lockTimeoutMs) {
            AdvisoryLock::acquire($lockKey, $lockTimeoutMs);

            $max = (clone $maxQuery)->max('sort_order');
            $next = is_null($max) ? 0 : ((int) $max + 1);

            return $create($next);
        });
    }
}
