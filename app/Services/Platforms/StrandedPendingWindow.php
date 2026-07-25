<?php

namespace App\Services\Platforms;

/**
 * Single source of truth for "a row still 'pending' this long after its last write
 * means the worker died, not that it is slow". Read by the connect poll
 * (DefersBespokeConnect, GenericPlatformController, ShopController), the refresh poll
 * (RefreshController) and the backlog alarm (CheckPlatformRefreshBacklogCommand), so
 * "stranded" means the same thing everywhere it is decided.
 *
 * Five minutes comfortably exceeds ConnectFetchJob's / ShopBrandConnectJob's 45s
 * timeout plus their two backoffs (5s, 20s).
 *
 * A published frontend-contract fact ("a pending row untouched for more than 5 minutes
 * reports failed" — docs/frontend-contracts/2026-07-23-platform-connect-async.md), so a
 * const and not a config/partna.php knob: an env-tunable value would let one
 * environment silently contradict the documented API.
 *
 * Neutral home for the same reason FetchUnavailableException owns STALE_CONNECT_ERROR —
 * the consumers are a controller trait, three controllers and an Artisan command, and
 * PHP cannot reference a trait constant externally.
 *
 * Exposes ONLY the number, deliberately: the call sites do not all compare the same way
 * (RefreshController::refreshStatus() is inclusive at the boundary where the poll
 * endpoints are exclusive), so a shared isStale()/cutoff() helper would flatten a real
 * behavioural difference rather than share a value.
 */
final class StrandedPendingWindow
{
    public const MINUTES = 5;
}
