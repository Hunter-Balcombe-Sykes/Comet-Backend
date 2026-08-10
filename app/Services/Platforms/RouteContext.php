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

    /**
     * Probes REFUSED because the budget was already spent. Counted separately
     * because the refusal is otherwise invisible: LinkRouter answers a starved
     * link with the same RouteResult::custom() a gate denial gives it, so a
     * caller cannot tell "we looked and it wasn't a shop" from "we never
     * looked". Found live 2026-08-10 — six nav links of one host consumed the
     * whole budget and the three links behind them were never examined.
     */
    private int $probesDenied = 0;

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
            $this->probesDenied++;

            return false;
        }

        $this->probeCount++;

        return true;
    }

    public function probesUsed(): int
    {
        return $this->probeCount;
    }

    public function probesDenied(): int
    {
        return $this->probesDenied;
    }

    /**
     * This run's budget accounting, for the caller's completion log.
     *
     * @return array{probe_budget: int, probes_spent: int, probes_denied: int}
     */
    public function summary(): array
    {
        return [
            'probe_budget' => $this->maxProbes,
            'probes_spent' => $this->probesUsed(),
            'probes_denied' => $this->probesDenied(),
        ];
    }
}
