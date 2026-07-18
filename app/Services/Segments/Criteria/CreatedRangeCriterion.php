<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Absolute signup-date window. Either bound may be set alone; both AND
 * together, and both compose with the relative TenureCriterion.
 */
final class CreatedRangeCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['created_from', 'created_to'];
    }

    public function rules(): array
    {
        return [
            'filters.created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'filters.created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:filters.created_from'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'created_from') || $this->hasValue($filters, 'created_to');
    }

    public function apply(Builder $query, array $filters): void
    {
        if ($this->hasValue($filters, 'created_from')) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['created_from'])->startOfDay());
        }

        if ($this->hasValue($filters, 'created_to')) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['created_to'])->endOfDay());
        }
    }
}
