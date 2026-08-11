<?php

namespace App\Ingest\Runtime\Effects;

/**
 * What a driver returns. Deliberately not a bare array: the caller must be able
 * to tell an empty answer from an absent one (see BilledEffectOutcome).
 */
final readonly class BilledEffectResult
{
    /** @param array<int|string, mixed>|null $data */
    private function __construct(
        public BilledEffectOutcome $outcome,
        public ?array $data,
        public ?string $reason = null,
    ) {}

    /** @param array<int|string, mixed>|null $data */
    public static function answered(?array $data): self
    {
        return new self(BilledEffectOutcome::Answered, $data);
    }

    /** $reason is operator-facing: it lands in ingest.effects.meta on the failed row. */
    public static function noAnswer(string $reason): self
    {
        return new self(BilledEffectOutcome::NoAnswer, null, $reason);
    }
}
