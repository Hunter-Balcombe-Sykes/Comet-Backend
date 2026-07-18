<?php

namespace App\Services\Segments\Criteria;

use App\Services\Profile\SectorTaxonomy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Match any of the given sector slugs. Accepts a bare string as well as an
 * array; a list that trims down to nothing applies no constraint at all
 * (preserved from the original resolver).
 */
final class SectorCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['sector'];
    }

    public function rules(): array
    {
        return [
            'filters.sector' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.sector.*' => ['string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! SectorTaxonomy::isValid($value)) {
                    $fail("Unknown sector slug: {$value}");
                }
            }],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'sector');
    }

    public function apply(Builder $query, array $filters): void
    {
        $sectors = array_values(array_filter(array_map(
            fn ($s) => is_string($s) ? trim($s) : null,
            is_array($filters['sector']) ? $filters['sector'] : [$filters['sector']]
        )));

        if ($sectors !== []) {
            $query->whereIn('sector', $sectors);
        }
    }
}
