<?php

namespace App\Services\Platforms;

use Illuminate\Database\Eloquent\Builder;

// Shared position allocation for menu categories and items (#INH-6) — one copy
// instead of the identical private methods MenuContentController and
// MenuScanApplier each carried.
//
// Kept separate from NormalizesMenuItemNames rather than merged into one "menu
// helpers" trait: MenuFetchJob and MenuMerger need the normalizer but never
// allocate positions, and an unused private method from a trait trips PHPStan's
// unused-private-method rule (level 4+, and this repo runs level 5).
trait AllocatesMenuPositions
{
    /** One past the current max `position` in $query (0 when empty). */
    private function nextPosition(Builder $query): int
    {
        return 1 + (int) ($query->max('position') ?? -1);
    }
}
