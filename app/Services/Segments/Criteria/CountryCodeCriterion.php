<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/**
 * ISO alpha-2 country match. The column is regex-validated at profile write
 * time, so no case folding is needed here.
 */
final class CountryCodeCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['country_code'];
    }

    public function rules(): array
    {
        return [
            'filters.country_code' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.country_code.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'country_code');
    }

    public function apply(Builder $query, array $filters): void
    {
        $codes = $this->stringList($filters['country_code']);

        if ($codes !== []) {
            $query->whereIn('country_code', $codes);
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            is_array($value) ? $value : [$value]
        ), fn (?string $v) => $v !== null && $v !== ''));
    }
}
