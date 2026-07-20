<?php

namespace App\Services\Http;

/**
 * Request/job-scoped wall-clock budget shared by every collaborator that
 * needs one — SafeUrlFetcher and YoutubeThumbnailResolver today; any future
 * bounded-fetch collaborator tomorrow.
 *
 * Deliberately extracted out of SafeUrlFetcher: the budget is a request-scoped
 * CONCERN, not a property of any one fetch client. A budget opened around a
 * whole platform connect must be visible to every collaborator that fetches
 * during it, not just the ones that happen to route through SafeUrlFetcher.
 * (Found 2026-07-20, W1 independent review: YoutubeThumbnailResolver::
 * pooledHead() deliberately uses raw Http::pool() rather than SafeUrlFetcher —
 * its i.ytimg.com URLs are hardcoded, never user input, so there's no SSRF
 * surface to guard — and was therefore invisible to a budget attached to
 * SafeUrlFetcher: ConnectResolver's budget bounded the channel-page and RSS
 * fetches but not the thumbnail probes on the same YouTube connect.)
 *
 * MUST be resolved from the container as a SCOPED binding (see
 * AppServiceProvider::register()) — every consumer within one open budget
 * must share the SAME instance, or the deadline set on one has zero effect
 * on another. scoped (not singleton) so Laravel's queue worker
 * (forgetScopedInstances() between jobs) can't leak a deadline from one job
 * into the next.
 */
class FetchBudget
{
    /**
     * Wall-clock deadline (hrtime seconds), or null when no budget is open —
     * the default for the overwhelming majority of the app's fetches.
     * hrtime(true) (not microtime()) because it is monotonic: a system clock
     * adjustment mid-request can't move it backwards and falsely extend or
     * shrink the budget.
     */
    private ?float $deadlineAt = null;

    /**
     * Run $work() with a wall-clock deadline open for its duration.
     *
     * The finally clears the deadline unconditionally — including when
     * $work() throws — so a failed/short-circuited operation can never leak
     * a stale deadline into the next unrelated call sharing this instance.
     * Callers must not nest open() calls (this app never needs to). Nesting
     * fails OPEN, not safe: the inner finally clears the OUTER deadline too,
     * so the outer operation carries on unbounded for the rest of its run.
     * That is preferable to silently combining two budgets into one wrong
     * number, but it is not a safe default — nesting is unsupported, not
     * merely discouraged, and nothing here detects or warns on it.
     *
     * NOT covered: a DNS stall inside SafeUrlFetcher::assertSafe()
     * (gethostbynamel() / dns_get_record()) has no timeout of its own and can
     * overshoot this deadline by however long the system resolver takes to
     * give up. Bounding that needs a resolver with its own timeout — out of
     * scope here.
     */
    public function open(float $seconds, callable $work): mixed
    {
        $this->deadlineAt = hrtime(true) / 1e9 + $seconds;

        try {
            return $work();
        } finally {
            $this->deadlineAt = null;
        }
    }

    /**
     * Seconds remaining on the open deadline, or null when no budget is set
     * (the default — see $deadlineAt). May be negative once the deadline has
     * passed; callers check `<= 0`, not falsiness.
     */
    public function remaining(): ?float
    {
        return $this->deadlineAt === null ? null : $this->deadlineAt - hrtime(true) / 1e9;
    }

    /** True once a deadline is open AND has passed. False when no budget is set at all. */
    public function exhausted(): bool
    {
        $remaining = $this->remaining();

        return $remaining !== null && $remaining <= 0;
    }
}
