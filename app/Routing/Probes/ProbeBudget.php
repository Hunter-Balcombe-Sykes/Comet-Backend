<?php

namespace App\Routing\Probes;

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\DailyCounterClaim;
use Illuminate\Support\Facades\Cache;

/**
 * Three-dimension spend ceiling for outbound link probes (plan §11).
 *
 * Probes are keyless, so there is no vendor invoice to cap — but they are
 * still outbound requests this backend makes on a user's say-so, and an
 * unbounded one is both a reliability risk to us (a slow host stalling a
 * queue) and an abuse vector aimed at someone else (a paste loop turning the
 * platform into a probe amplifier). The dimensions answer three different
 * failures:
 *
 *   global per-day  — one runaway import cannot exhaust the platform's
 *                     outbound capacity for everyone else
 *   user per-day    — one account cannot make us hammer a third party
 *   per-run         — one import cannot spend its whole daily allowance on a
 *                     single 200-link page
 *
 * The two daily dimensions claim through {@see DailyCounterClaim}, which owns
 * the atomicity and TTL guarantees — this class was where that mechanism was
 * proven, and it now shares it with the three spend budgets rather than
 * keeping a private copy for them to be mirrored from. A rejected claim
 * releases every counter it took, so it costs nothing. The per-run dimension
 * stays here: it is in-process state, not a shared counter.
 */
class ProbeBudget
{
    /** Probes spent by the CURRENT run. Request/job-scoped, like FetchBudget. */
    private int $spentThisRun = 0;

    public function tryClaim(?string $userId): bool
    {
        if ($this->spentThisRun >= self::perRunCap()) {
            return false;
        }

        $globalCap = self::globalDailyCap();
        $userCap = self::userDailyCap();
        $date = now()->format('Y-m-d');

        $globalKey = CacheKeyGenerator::routingProbeGlobalDaily($date);

        if (! DailyCounterClaim::claim($globalKey, $globalCap)) {
            return false;
        }

        // Pre-account and staff builds have no user to charge; the global and
        // per-run ceilings still bound them.
        if ($userId !== null) {
            $userKey = CacheKeyGenerator::routingProbeUserDaily($userId, $date);

            if (! DailyCounterClaim::claim($userKey, $userCap)) {
                DailyCounterClaim::release($globalKey);

                return false;
            }
        }

        $this->spentThisRun++;

        return true;
    }

    /** Which ceiling stopped the claim — for the run card and the observation. */
    public function exhaustedDimension(?string $userId): string
    {
        if ($this->spentThisRun >= self::perRunCap()) {
            return 'per_run';
        }

        $date = now()->format('Y-m-d');

        if ((int) Cache::get(CacheKeyGenerator::routingProbeGlobalDaily($date), 0) >= self::globalDailyCap()) {
            return 'global_daily';
        }

        if ($userId !== null
            && (int) Cache::get(CacheKeyGenerator::routingProbeUserDaily($userId, $date), 0) >= self::userDailyCap()) {
            return 'user_daily';
        }

        return 'unknown';
    }

    public function spentThisRun(): int
    {
        return $this->spentThisRun;
    }

    /**
     * A batch importer routes many links through one worker; the per-run
     * dimension only means anything if the run says where it starts.
     */
    public function startRun(): void
    {
        $this->spentThisRun = 0;
    }

    public static function perRunCap(): int
    {
        return (int) config('partna.routing.probe.per_run_cap', 6);
    }

    public static function globalDailyCap(): int
    {
        return (int) config('partna.routing.probe.global_daily_cap', 2000);
    }

    public static function userDailyCap(): int
    {
        return (int) config('partna.routing.probe.user_daily_cap', 40);
    }
}
