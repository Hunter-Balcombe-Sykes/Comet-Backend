<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/**
 * Free-text state/region match, case-insensitive on both sides.
 * Best-effort by design: users who left the field blank never match, because
 * LOWER(NULL) is NULL and NULL is never IN a list.
 */
final class LocationStateCriterion implements SegmentCriterion
{
    use MatchesFreeTextLocation;
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['location_state'];
    }

    public function rules(): array
    {
        return [
            'filters.location_state' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.location_state.*' => ['string', 'max:255'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'location_state');
    }

    public function apply(Builder $query, array $filters): void
    {
        $this->whereLowerIn($query, 'location_state', $filters['location_state']);
    }
}
