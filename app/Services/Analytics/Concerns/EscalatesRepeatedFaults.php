<?php

namespace App\Services\Analytics\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Escalates a SUSTAINED run of fail-open catches to Nightwatch, while a single
 * blip stays a quiet Log::warning breadcrumb — Nightwatch never alerts on
 * Log::warning (see CLAUDE.md).
 *
 * INVARIANT: this only fires if the cache fault THROWS — CACHE_STORE must not
 * be a failover-chained store, which swallows the primary leg's Throwable and
 * silences both tiers below. Guarded by CacheStoreFailoverGuardTest.
 *
 * TIER 1 (deterministic): RateLimiter::hit() counts faults in a cache-backed,
 * fleet-wide bucket keyed by call-site label — PHP-FPM is shared-nothing (no
 * Octane), so a `static` counter would reset every request and miss a
 * sustained outage. report() fires exactly once per FAULT_WINDOW_SECONDS
 * window, the instant the count reaches FAULT_THRESHOLD (`===`, not `>=` —
 * one report per window, not one per fault after).
 *
 * TIER 2 (sampled fallback): Tier 1 depends on the cache itself — at
 * bumpVersion()/claim() the cache IS the subsystem being caught for, so you
 * cannot count failures of X using X. If RateLimiter::hit()'s own counter
 * write throws, fall back to a stateless 1-in-FAULT_SAMPLE_RATE random sample.
 *
 * HONEST LIMIT: below ~FAULT_THRESHOLD faults inside one FAULT_WINDOW_SECONDS
 * window, neither tier alerts. That's indistinguishable from a single blip,
 * by design.
 *
 * Nothing in this trait can throw out of the caller's catch block: Tier 1's
 * try/catch falls through to Tier 2 on any failure, and Tier 2's own
 * report() call is separately guarded.
 */
trait EscalatesRepeatedFaults
{
    /**
     * Faults inside one FAULT_WINDOW_SECONDS window before report() fires.
     * 5 faults in 10 minutes trips at both ends of Partna's traffic curve —
     * roughly 1/min at today's pre-beta trickle, or a fraction of a second at
     * the ~1M-beacons/day target (~700/min) — without re-firing on every
     * fault after the 5th (see `===` above).
     */
    public const FAULT_THRESHOLD = 5;

    /** Window width for the Tier 1 fleet-wide fault counter, via RateLimiter. */
    public const FAULT_WINDOW_SECONDS = 600;

    /**
     * Sampling rate for Tier 2 — only used once the counter store itself is
     * unreachable. 1-in-200: a sustained outage still surfaces within the
     * first few dozen faults at low volume, and caps the fallback stream at
     * roughly 3-4 reports/minute at the ~1M-beacons/day target.
     */
    public const FAULT_SAMPLE_RATE = 200;

    /**
     * Record one fault for $label — a short, call-site-specific string (e.g.
     * 'ingest', 'cache_bump', 'dedup') so the three call sites that share
     * this trait never merge into one counter. Never throws: a Tier 1
     * failure falls through to Tier 2, and Tier 2's own report() is guarded
     * too — a broken exception reporter must never turn a fail-open path
     * into a fail-closed one.
     */
    protected static function escalateIfSustained(Throwable $e, string $label): void
    {
        // Outer catch-all: the hard requirement is that NOTHING can throw out
        // of the caller's catch block, no matter what goes wrong below — this
        // is the last-resort net, on top of the Tier 1 → Tier 2 fallback
        // (inner try/catch) and reportSafely()'s own guard.
        try {
            try {
                $hits = RateLimiter::hit("analytics:fault:{$label}", self::FAULT_WINDOW_SECONDS);

                // Strict equality: only the window's Nth fault reports.
                if ($hits === self::FAULT_THRESHOLD) {
                    self::reportSafely($e);
                }
            } catch (Throwable) {
                // Tier 1 depends on the very cache store these three call sites
                // are catching faults FOR. If the counter itself throws, the
                // cache is genuinely down and we cannot count anything reliably
                // — fall back to a stateless sample so a sustained outage still
                // surfaces.
                self::sampledReport($e);
            }
        } catch (Throwable) {
            // Absolute last resort — see comment above the outer try.
        }
    }

    /**
     * mt_rand (not random_int): deterministic enough for tests to seed via
     * mt_srand(), which a CSPRNG like random_int() cannot be. This is a
     * sampling decision, not a security boundary, so a predictable PRNG is
     * fine here.
     */
    private static function sampledReport(Throwable $e): void
    {
        if (mt_rand(1, self::FAULT_SAMPLE_RATE) === 1) {
            self::reportSafely($e);
        }
    }

    /** report() must never be able to break a fail-open path. */
    private static function reportSafely(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // The reporter itself must never be able to break a fail-open path.
        }
    }
}
