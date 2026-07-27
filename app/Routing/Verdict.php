<?php

namespace App\Routing;

/**
 * The sealed set of answers PlacementPolicy can give. Every routed link ends
 * on exactly one of these, and each maps to a definite downstream action —
 * there is no "other" branch anywhere in the pipeline.
 */
enum Verdict: string
{
    /** Confident and permitted: create/refresh the intent, apply it now. */
    case Place = 'place';

    /** Plausible but not certain: surface as a suggestion the user confirms. */
    case Choose = 'choose';

    /** Recognised, not connectable here: keep as a link item, never dropped. */
    case Note = 'note';

    /** Blocked by state we expect to change (conflict, cap): keep, surface it. */
    case Hold = 'hold';

    /** Never write: tombstoned, unroutable, own-infra, reserved. */
    case Reject = 'reject';

    public function writesIntent(): bool
    {
        return $this === self::Place || $this === self::Choose || $this === self::Hold;
    }

    public function appliesImmediately(): bool
    {
        return $this === self::Place;
    }

    /** The state a written intent starts in for this verdict. */
    public function intentState(): ?string
    {
        return match ($this) {
            self::Place => 'applied',
            self::Choose => 'proposed',
            self::Hold => 'blocked',
            default => null,
        };
    }
}
