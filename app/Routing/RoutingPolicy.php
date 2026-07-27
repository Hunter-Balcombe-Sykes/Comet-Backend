<?php

namespace App\Routing;

/**
 * Confidence thresholds, per routing class, on the projector's 0–100 scale
 * (plan §2: the specificity formula is a build-time gate; THIS is the
 * runtime write gate). Retuned from real traffic via `detector_observations`
 * — the numbers below are the launch defaults, deliberately conservative for
 * the classes where a wrong write is user-visible on the sitepage.
 *
 * Two knobs per class:
 *   auto   — at or above this, a direct paste auto-applies
 *   suggest— at or above this, it becomes a confirmable suggestion
 *            (below it, the link is kept as a plain link item and nothing
 *            is proposed at all)
 */
class RoutingPolicy
{
    /** @var array<string, array{auto: int, suggest: int}> */
    private const THRESHOLDS = [
        // A wrong CTA is the most visible error a sitepage can make: a booking
        // or ordering button pointing at the wrong venue. Highest bar.
        'booking' => ['auto' => 80, 'suggest' => 55],
        'reservations' => ['auto' => 80, 'suggest' => 55],
        'ordering' => ['auto' => 80, 'suggest' => 55],
        'shop' => ['auto' => 75, 'suggest' => 50],
        // Content/social are cheap to correct and high-volume; a lower bar
        // keeps the common case one-step.
        'social' => ['auto' => 70, 'suggest' => 45],
        'content' => ['auto' => 70, 'suggest' => 45],
        'events' => ['auto' => 72, 'suggest' => 50],
        // A link item is the fallback, so "link" never needs a high bar.
        'link' => ['auto' => 0, 'suggest' => 0],
    ];

    /** Margin below which two rules are considered too close to auto-apply. */
    private const MIN_MARGIN = 10;

    /** Harvested (not pasted) links need more confidence to write unattended. */
    private const INDIRECT_PENALTY = 10;

    public static function autoThreshold(string $routingClass): int
    {
        return self::THRESHOLDS[$routingClass]['auto'] ?? 75;
    }

    public static function suggestThreshold(string $routingClass): int
    {
        return self::THRESHOLDS[$routingClass]['suggest'] ?? 50;
    }

    public static function minMargin(): int
    {
        return self::MIN_MARGIN;
    }

    public static function indirectPenalty(): int
    {
        return self::INDIRECT_PENALTY;
    }

    /** The Ignore class never writes, whatever the confidence (plan §2). */
    public static function isIgnored(string $routingClass): bool
    {
        return $routingClass === 'ignore';
    }
}
