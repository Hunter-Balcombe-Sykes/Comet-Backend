<?php

namespace App\Services\Segments\Criteria;

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
}
