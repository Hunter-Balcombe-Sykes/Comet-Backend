<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/** `true` → any active platform connection; a string → that platform only. */
final class HasIntegrationCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['has_integration'];
    }

    public function rules(): array
    {
        return [
            'filters.has_integration' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value) && ! (is_string($value) && preg_match('/^[a-z][a-z0-9_-]*$/', $value))) {
                    $fail('has_integration must be true or a platform key.');
                }
            }],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'has_integration');
    }

    public function apply(Builder $query, array $filters): void
    {
        $platform = is_string($filters['has_integration']) ? $filters['has_integration'] : null;

        $query->whereHas('integrationConnections', function ($q) use ($platform): void {
            $q->where('is_active', true);
            if ($platform !== null) {
                $q->where('platform', $platform);
            }
        });
    }
}
