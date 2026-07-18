<?php

namespace App\Services\Segments\Criteria;

use Closure;

/** Shared activation helpers — the house rules for "is this key set". */
trait ResolvesFilterValues
{
    /**
     * A scalar/array key counts as set when present and neither null nor ''.
     * Note `false` IS a value (early_access => false is a real constraint).
     */
    protected function hasValue(array $filters, string $key): bool
    {
        return array_key_exists($key, $filters)
            && $filters[$key] !== null
            && $filters[$key] !== '';
    }

    /**
     * Object-key config with nulls stripped, so `{}` and `{"min": null}` are
     * both inert. Non-array values yield [].
     *
     * @return array<string, mixed>
     */
    protected function objectConfig(array $filters, string $key): array
    {
        $value = $filters[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Validation closure for object criteria: requires at least one of
     * min/max, which no structural Laravel rule can express. Non-array values
     * pass through — that shape is rejected elsewhere by the sibling 'array'
     * rule, not here.
     */
    protected function requiresABound(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['min'] ?? null) === null && ($value['max'] ?? null) === null) {
                $fail($message);
            }
        };
    }
}
