<?php

namespace App\Routing;

/**
 * PlacementPolicy's decision about one projection, for one caller context.
 * Carries the reason in every non-Place case so the suggestions inbox, the
 * staff stuck-intents view, and `routing:reproject` all read the same words.
 */
final readonly class Placement
{
    public function __construct(
        public Verdict $verdict,
        public ?string $surfaceKey,
        public ?string $identifier,
        public ?string $blockReason = null,
        public ?string $explanation = null,
        public ?string $conflictingConnectionId = null,
    ) {}

    public static function reject(string $reason, ?string $surfaceKey = null): self
    {
        return new self(Verdict::Reject, $surfaceKey, null, $reason);
    }
}
