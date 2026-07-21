<?php

namespace App\Services\Platforms;

use App\Services\Http\FetchBudget;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\DeferredConnect;

/**
 * Single entry point GenericPlatformController::connect() delegates to.
 * Wraps the descriptor's ConnectStrategy::resolve() (parse + any upstream
 * fetch) — or, on the deferred path, DeferredConnect::identify() (cheap
 * syntactic validation only) — in FetchBudget::open(), so a slow/unresponsive
 * vendor can no longer hold the request thread for the multi-hop x
 * multi-retry worst case a flat per-hop timeout alone allows (see
 * docs/superpowers/plans/2026-07-20-platform-connect-async.md §1a).
 *
 * Depends on FetchBudget directly, NOT on SafeUrlFetcher — the budget is a
 * request-scoped concern shared across every collaborator a strategy's
 * resolve()/identify() might call (SafeUrlFetcher-routed fetches AND
 * YoutubeThumbnailResolver's raw-Http::pool() probes alike; see
 * FetchBudget's docblock for why that split exists).
 *
 * Unit 11 W6 — the branch decision: a platform takes the deferred path only
 * when BOTH the descriptor declares support AND the platform is named in the
 * rollout flag (config('partna.connect.deferred'), empty by default — async
 * off everywhere on merge). This is the one place in the app that reads that
 * flag; a platform's behaviour is decided here, once, not re-derived at every
 * call site.
 *
 * `instanceof DeferredConnect` below is SAFE here — unlike the boot-time route
 * loop (routes/api/platforms.php), which must read supportsDeferredConnect()
 * and never resolve connectStrategy(), this method runs at REQUEST time,
 * after the controller has already resolved the strategy once for its own
 * null-check. Resolving it again here bakes in nothing that wasn't already
 * baked in.
 *
 * Returns a ConnectOutcome, not a bare ConnectResult — see that class's
 * docblock for why the widening takes this shape rather than a
 * `ConnectResult::pending()` variant.
 */
class ConnectResolver
{
    public function __construct(private readonly FetchBudget $budget) {}

    public function resolve(PlatformDescriptor $descriptor, string $input): ConnectOutcome
    {
        // Re-resolved from the descriptor rather than passed in: the caller
        // (GenericPlatformController::connect()) already calls
        // connectStrategy() once for its own null-check guard before reaching
        // this method. The factory is a cheap lazy Closure (same boot-safety
        // rationale as PlatformDescriptor::connectStrategy() itself), so a
        // second resolution costs nothing correctness-wise.
        $strategy = $descriptor->connectStrategy();
        if ($strategy === null) {
            // Unreachable via the controller today (it aborts 404 first) —
            // defensive only, so this method has no undefined behaviour if
            // ever called directly (e.g. from a future queued job).
            return ConnectOutcome::complete(ConnectResult::fail($descriptor->connectErrorMessage(), 404));
        }

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);

        // Narrowing $strategy to DeferredConnect INSIDE this branch (rather than
        // a top-level ternary) keeps ->identify() statically callable — the
        // arrow function below captures the narrowed type.
        if ($descriptor->supportsDeferredConnect()
            && $strategy instanceof DeferredConnect
            && in_array($descriptor->key(), config('partna.connect.deferred', []), true)
        ) {
            return ConnectOutcome::pending($this->budget->open($seconds, fn () => $strategy->identify($input)));
        }

        return ConnectOutcome::complete($this->budget->open($seconds, fn () => $strategy->resolve($input)));
    }
}
