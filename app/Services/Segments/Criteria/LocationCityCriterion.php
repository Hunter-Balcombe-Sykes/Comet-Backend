<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/** Free-text city match — same semantics as LocationStateCriterion. */
final class LocationCityCriterion implements SegmentCriterion
{
    use MatchesFreeTextLocation;
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['location_city'];
    }

    public function rules(): array
    {
        return [
            'filters.location_city' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.location_city.*' => ['string', 'max:255'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'location_city');
    }

    public function apply(Builder $query, array $filters): void
    {
        $this->whereLowerIn($query, 'location_city', $filters['location_city']);
    }
}
