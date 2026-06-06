<?php

namespace App\DTOs\Moderation;

/**
 * Immutable input DTO for a staff escalation action on a moderation case.
 * Converts the human-facing target name to the decision_type expected by
 * ModerationDecisionService::decide().
 */
final class EscalationDto
{
    public function __construct(
        public readonly string $target,  // 'law_enforcement' | 'esafety'
        public readonly string $notes,
    ) {}

    public function toDecisionType(): string
    {
        return match ($this->target) {
            'law_enforcement' => 'escalate_law_enforcement',
            'esafety'         => 'escalate_esafety',
            // $target is typed `string`, so the match can't be exhaustive at the
            // type level. Throw explicitly rather than leaking an UnhandledMatchError
            // — an unexpected target is a programming error, not user input.
            default => throw new \InvalidArgumentException("Unknown escalation target: {$this->target}"),
        };
    }
}
