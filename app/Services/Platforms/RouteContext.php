<?php

namespace App\Services\Platforms;

/**
 * Per-RUN context for LinkRouter — carries the seen-platforms dedupe map
 * (Issue M: first bio link per platform wins) and the commerce probe budget
 * (bounded fan-out per link-in-bio page / per scan).
 *
 * MUST be created once per calling LOOP and threaded through every route() call
 * in that loop. A fresh instance per URL silently disables BOTH guards, which
 * is exactly how the probe cap went missing when the inline
 * MAX_COMMERCE_PROBES counters were deleted from LinkInBioScanJob and
 * InstagramConnectionSeeder::autoSaveUnmatchedLinks. CustomLinkSeeder::seed()
 * therefore accepts one; loops pass theirs, and only genuine single-URL entry
 * points (CustomLinksController::addLink) let it default.
 */
final class RouteContext
{
    /**
     * Default probe budget per run — the value both deleted inline counters
     * used (signup-v2 C4). Pinned against them by LinkRouterProbeCapTest.
     */
    public const DEFAULT_MAX_PROBES = 6;

    /** @var array<string, true> */
    public array $seenPlatforms = [];

    private int $probeCount = 0;

    public function __construct(public readonly int $maxProbes = self::DEFAULT_MAX_PROBES) {}

    /**
     * Claim one probe from this run's budget. False when the budget is spent —
     * the caller must then fall back to a custom link rather than dispatch, so
     * links past the budget keep the pre-refactor straight-to-custom-link
     * behaviour and nothing vanishes.
     */
    public function consumeProbe(): bool
    {
        if ($this->probeCount >= $this->maxProbes) {
            return false;
        }

        $this->probeCount++;

        return true;
    }

    public function probesUsed(): int
    {
        return $this->probeCount;
    }
}
