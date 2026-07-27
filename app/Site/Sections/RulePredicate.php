<?php

namespace App\Site\Sections;

/** One clause of a section rule. */
final readonly class RulePredicate
{
    /** @param list<string> $values */
    public function __construct(
        public RuleOperator $operator,
        public array $values,
        public bool $negated = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $operator = RuleOperator::tryFrom((string) ($data['op'] ?? ''));
        if ($operator === null) {
            throw new \InvalidArgumentException('Unknown rule operator: '.($data['op'] ?? '(none)'));
        }

        return new self(
            operator: $operator,
            values: array_values(array_map('strval', (array) ($data['values'] ?? []))),
            negated: (bool) ($data['not'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'op' => $this->operator->value,
            'values' => $this->values,
            'not' => $this->negated ?: null,
        ], fn ($v) => $v !== null);
    }
}
