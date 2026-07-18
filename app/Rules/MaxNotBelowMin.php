<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "max must be >= min" — but only when a min was actually supplied.
 *
 * Laravel's built-in `gte:other` rejects the field outright when `other` is
 * absent or null (it compares against nothing), which would make every
 * max-only filter impossible to save — including the deliberate max-only
 * analytics shape used to target low-traffic users. This rule is a no-op
 * unless both bounds are present and numeric.
 */
final class MaxNotBelowMin implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $minPath) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $min = data_get($this->data, $this->minPath);

        if ($min === null || ! is_numeric($value) || ! is_numeric($min)) {
            return;
        }

        if ($value + 0 < $min + 0) {
            $fail("The {$attribute} field must be greater than or equal to {$this->minPath}.");
        }
    }
}
