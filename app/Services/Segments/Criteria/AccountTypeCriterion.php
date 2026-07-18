<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** Exact match on core.users.account_type. */
final class AccountTypeCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['account_type'];
    }

    public function rules(): array
    {
        return [
            'filters.account_type' => ['sometimes', 'nullable', 'string', Rule::in(['partna', 'business'])],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'account_type');
    }

    public function apply(Builder $query, array $filters): void
    {
        $query->where('account_type', (string) $filters['account_type']);
    }
}
