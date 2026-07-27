<?php

namespace App\Routing;

/**
 * The projector's answer for one Iri: which surface (if any), what identifier
 * was captured, how sure we are, and why. Confidence is 0–100; `margin` is
 * the gap to the runner-up candidate, which is what distinguishes "clearly
 * this" from "two rules both nearly fit".
 */
final readonly class Projection
{
    public function __construct(
        public ?string $surfaceKey,
        public ?string $detectorId,
        /** @var array<string, string> */
        public array $captures,
        public int $confidence,
        public int $margin,
        public ?string $identifier,
        public ?string $reason,
        /** @var list<array{surface: string, detector: string, confidence: int}> */
        public array $alternatives = [],
    ) {}

    public function matched(): bool
    {
        return $this->surfaceKey !== null;
    }

    public static function none(string $reason): self
    {
        return new self(null, null, [], 0, 0, null, $reason);
    }
}
