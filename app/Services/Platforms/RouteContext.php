<?php

namespace App\Services\Platforms;

/**
 * Per-run context for LinkRouter — carries the seen-platforms dedupe map
 * (Issue M: first bio link per platform wins) and the commerce probe cap
 * (bounded fan-out per link-in-bio page).
 *
 * Owned by the calling loop. CustomLinksController::addLink() passes a fresh
 * one (single URL, no dedupe needed). InstagramAutoSync and LinkInBioScanJob
 * pass one per seed/scan run.
 */
final class RouteContext
{
    /** @var array<string, true> */
    public array $seenPlatforms = [];

    public int $probeCount = 0;

    public int $maxProbes;

    public function __construct(int $maxProbes = 6)
    {
        $this->maxProbes = $maxProbes;
    }
}
