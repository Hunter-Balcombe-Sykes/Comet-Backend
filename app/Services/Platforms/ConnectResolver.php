<?php

namespace App\Services\Platforms;

use App\Services\Http\FetchBudget;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;

/**
 * Single entry point GenericPlatformController::connect() delegates to.
 * Wraps the descriptor's ConnectStrategy::resolve() (parse + any upstream
 * fetch) in FetchBudget::open(), so a slow/unresponsive vendor can no longer
 * hold the request thread for the multi-hop x multi-retry worst case a flat
 * per-hop timeout alone allows (see
 * docs/superpowers/plans/2026-07-20-platform-connect-async.md §1a).
 *
 * Depends on FetchBudget directly, NOT on SafeUrlFetcher — the budget is a
 * request-scoped concern shared across every collaborator a strategy's
 * resolve() might call (SafeUrlFetcher-routed fetches AND
 * YoutubeThumbnailResolver's raw-Http::pool() probes alike; see
 * FetchBudget's docblock for why that split exists).
 *
 * NOTE — Phase 2 coupling (same plan, §2c/§7): this method returns a bare
 * ConnectResult for now, but a later phase widens the return type to an
 * outcome object that can also carry a "pending" (async/queued) result.
 * That widening is deliberately NOT done here — this class is built to
 * survive it without being reopened, not to anticipate its exact shape.
 */
class ConnectResolver
{
    public function __construct(private readonly FetchBudget $budget) {}

    public function resolve(PlatformDescriptor $descriptor, string $input): ConnectResult
    {
        // Re-resolved from the descriptor rather than passed in: the caller
        // (GenericPlatformController::connect()) already calls
        // connectStrategy() once for its own null-check guard before reaching
        // this method. The factory is a cheap lazy Closure (same boot-safety
        // rationale as PlatformDescriptor::connectStrategy() itself), so a
        // second resolution costs nothing correctness-wise and keeps this
        // method's signature (descriptor + input) stable for Phase 2.
        $strategy = $descriptor->connectStrategy();
        if ($strategy === null) {
            // Unreachable via the controller today (it aborts 404 first) —
            // defensive only, so this method has no undefined behaviour if
            // ever called directly (e.g. from a future queued job).
            return ConnectResult::fail($descriptor->connectErrorMessage(), 404);
        }

        return $this->budget->open(
            (float) config('partna.http_fetch.connect_budget_seconds', 20),
            fn () => $strategy->resolve($input),
        );
    }
}
