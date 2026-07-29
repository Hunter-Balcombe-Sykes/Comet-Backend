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
     *
     * Generic over the wrapped callable's return so opening a budget is
     * type-transparent: a bare `mixed` return would erase the array shapes
     * callers rely on (e.g. EventsCatalog::addByUrl()'s
     * array{ok: bool, error?: string, ...}), silently downgrading static
     * analysis at every wrapped call site.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function open(float $seconds, callable $work): mixed
    {
        $this->deadlineAt = $this->nowSeconds() + $seconds;

        try {
            return $work();
        } finally {
            $this->deadlineAt = null;
        }
    }

    /**
     * Like open(), but re-entrant: if a budget is ALREADY open on this instance,
     * run $work() inside it untouched (the OUTERMOST deadline governs) rather than
     * starting — and, on return, clearing — a second one. This is what lets a
     * shared chokepoint (e.g. PlatformRefresher) guarantee "bounded" without
     * caring whether an outer caller already opened a budget. When none is open,
     * behaves exactly like open().
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function ensureOpen(float $seconds, callable $work): mixed
    {
        if ($this->deadlineAt !== null) {
            return $work();          // inside an open budget — outermost wins, do not touch the deadline
        }

        return $this->open($seconds, $work);
    }

    /**
     * Seconds remaining on the open deadline, or null when no budget is set
     * (the default — see $deadlineAt). May be negative once the deadline has
     * passed; callers check `<= 0`, not falsiness.
     */
    public function remaining(): ?float
    {
        return $this->deadlineAt === null ? null : $this->deadlineAt - $this->nowSeconds();
    }

    /**
     * The monotonic clock, as a seam.
     *
     * hrtime(true) (not microtime()) because it is monotonic: a system clock
     * adjustment mid-request cannot move it backwards and falsely extend or
     * shrink the budget. It is a protected method rather than an inline call so
     * a test can subclass and drive it.
     *
     * That seam is not cosmetic. The regression test for "the budget reaches
     * the thumbnail-probe pool, not just the SafeUrlFetcher legs" previously
     * set a 100ms budget and slept 150ms inside an Http::fake, then asserted
     * that exactly one probe fired. It was really asserting that three real
     * fetches complete in under 100ms of REAL time — true on a quiet laptop,
     * false on a loaded CI runner, where the budget expires before the
     * thumbnail phase starts and zero probes fire. That is a genuine CI flake
     * on wall-clock timing, and no amount of widening the margin removes it;
     * only removing wall-clock from the assertion does.
     */
    protected function nowSeconds(): float
    {
        return hrtime(true) / 1e9;
    }

    /** True once a deadline is open AND has passed. False when no budget is set at all. */
    public function exhausted(): bool
    {
        $remaining = $this->remaining();

        return $remaining !== null && $remaining <= 0;
    }
}
