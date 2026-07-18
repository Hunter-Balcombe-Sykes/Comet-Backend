<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Case-insensitive exact match against a free-text profile column.
 * Column name is supplied by the criterion, never by user input.
 */
trait MatchesFreeTextLocation
{
    protected function whereLowerIn(Builder $query, string $column, mixed $value): void
    {
        $needles = array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : null,
            is_array($value) ? $value : [$value]
        ), fn (?string $v) => $v !== null && $v !== ''));

        if ($needles === []) {
            return;
        }

        $query->whereIn(DB::raw("LOWER({$column})"), $needles);
    }
}
