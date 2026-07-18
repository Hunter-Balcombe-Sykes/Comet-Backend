<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
use Illuminate\Database\Eloquent\Builder;

/**
 * Relative time-on-Partna, over the same created_at column as the absolute
 * CreatedRangeCriterion — all four bounds compose by AND.
 *
 * Evaluated at resolve time, so a tenure segment stays current with zero
 * maintenance. All date math is done in PHP and bound as a parameter; no SQL
 * date arithmetic, which keeps it identical on Postgres and SQLite.
 */
final class TenureCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['tenure_days_min', 'tenure_days_max'];
    }

    public function rules(): array
    {
        return [
            'filters.tenure_days_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'filters.tenure_days_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650', new MaxNotBelowMin('filters.tenure_days_min')],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'tenure_days_min') || $this->hasValue($filters, 'tenure_days_max');
    }

    public function apply(Builder $query, array $filters): void
    {
        // "on Partna >= N days" means they signed up at or before N days ago.
        if ($this->hasValue($filters, 'tenure_days_min')) {
            $query->where('created_at', '<=', now()->subDays((int) $filters['tenure_days_min']));
        }

        if ($this->hasValue($filters, 'tenure_days_max')) {
            $query->where('created_at', '>=', now()->subDays((int) $filters['tenure_days_max']));
        }
    }
}
