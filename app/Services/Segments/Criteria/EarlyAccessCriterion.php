<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/** Membership in the early-access programme, keyed by primary email. */
final class EarlyAccessCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['early_access'];
    }

    public function rules(): array
    {
        return [
            'filters.early_access' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'early_access');
    }

    public function apply(Builder $query, array $filters): void
    {
        $exists = 'EXISTS (SELECT 1 FROM core.early_access_signups eas WHERE eas.email_lc = LOWER(core.users.primary_email))';

        (bool) $filters['early_access']
            ? $query->whereRaw($exists)
            : $query->whereRaw("NOT {$exists}");
    }
}
