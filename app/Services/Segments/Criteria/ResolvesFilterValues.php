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
     *
     * Note the asymmetry: `min: 0` does NOT satisfy this (see isLowerBound),
     * but `max: 0` does — "at most zero" is a real constraint. So
     * `{min: 0, max: 0}` passes and `{min: 0}` alone is a 422.
     */
    protected function requiresABound(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (! is_array($value)) {
                return;
            }

            if (! self::isLowerBound($value['min'] ?? null) && ($value['max'] ?? null) === null) {
                $fail($message);
            }
        };
    }

    /**
     * Is this `min` an actual lower bound, or the no-op zero?
     *
     * Every quantity these criteria bound (event counts, follower counts) is
     * non-negative, so `>= 0` constrains nothing — it reads as "no minimum".
     * Taking it literally is actively harmful in AnalyticsCriterion, where the
     * min branch is an EXISTS/GROUP BY that silently excludes zero-activity
     * users; see the branch comments there.
     *
     * Non-numeric values count as a bound so the sibling `integer` rule owns
     * reporting them — otherwise `min: "abc"` would surface as the misleading
     * "requires at least one of min or max".
     */
    protected static function isLowerBound(mixed $min): bool
    {
        if ($min === null || $min === '') {
            return false;
        }

        return ! (is_numeric($min) && (int) $min === 0);
    }
}
