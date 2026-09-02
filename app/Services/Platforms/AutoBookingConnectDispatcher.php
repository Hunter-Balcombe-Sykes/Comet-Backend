<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\ConnectFetchJob;
use App\Jobs\Platforms\SquareAutoSelectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\DailyCounterClaim;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Facades\Log;

/**
 * The one place an auto-discovered Fresha row is handed to ConnectFetchJob,
 * and the one place the install-wide daily ceiling on salon scrapes is claimed.
 *
 * Extracted from BuildsAutoSyncFindings (2026-08-19) because a THIRD producer
 * needed it and could not take the trait. LinkRouter and GoogleBusinessAutoSync
 * are legacy-lane classes that own `write()`/`resolveBookingLink()`; the routing
 * lane's SourceReconciler is the single writer for its own lane and must not
 * acquire a second connection-writing API just to reach one dispatch — its
 * docblock's single-writer property is what makes the intent ledger a true
 * account of why every connection exists.
 *
 * The trait's two methods remain, delegating here, so both existing producers
 * keep calling $this->dispatchAutoBookingConnect() unchanged and the shared-cap
 * invariant (tests/Unit/Platforms/RouteContextOriginTest.php) still holds — now
 * across three callers instead of two.
 */
final class AutoBookingConnectDispatcher
{
    /**
     * Hand a freshly auto-seeded booking connection to whatever that
     * provider's auto-connect actually is.
     *
     * The providers differ in KIND, not merely in detail, which is why this is
     * a dispatch table and not one parameterised path: Fresha needs its salon
     * scraped (ConnectFetchJob against the seeded row), Square needs no scrape
     * at all — SquareAutoSelectJob reads the booking page's own widget JSON and
     * stamps team_member_id onto the URL. Both spend the same install-wide
     * daily budget, claimed ABOVE the table so a provider added later cannot
     * quietly escape the ceiling.
     *
     * A match rather than an `if`, because the `if` shape had a real failure
     * mode: anything that was not Square fell through to Fresha's branch and
     * dispatched a Fresha scrape for it. Both callers gate on
     * BookingProviders::PLATFORMS, so no live path reached it — but a third
     * provider arriving here without an arm should be a log line, never a
     * Fresha fetch against a row that is not Fresha's.
     */
    public function dispatchFor(string $userId, string $platform = 'fresha'): void
    {
        if (! $this->claimBudget()) {
            return;
        }

        match ($platform) {
            Platform::Fresha->value => $this->dispatchFreshaScrape($userId),
            Platform::Square->value => $this->dispatchSquareAutoSelect($userId),
            default => Log::warning('auto_booking_connect.no_provider_arm', [
                'user_id' => $userId,
                'platform' => $platform,
            ]),
        };
    }

    /**
     * Fresha: re-query the seeded row and scrape it.
     *
     * Re-queried rather than passed in: the two legacy producers reach a seeded
     * row by completely different routes, and widening their return types to
     * carry an id is blast radius this is scoped to avoid.
     *
     * connectMode is stamped HERE, not at the write, because the write helpers
     * are shared with origins that must not be marked auto.
     */
    private function dispatchFreshaScrape(string $userId): void
    {
        $row = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', Platform::Fresha->value)
            ->first();

        if ($row === null) {
            return;
        }

        $row->forceFill(['payload' => [...$row->payload, 'connectMode' => 'auto']])->saveQuietly();

        ConnectFetchJob::dispatch((string) $row->id, Platform::Fresha->value, systemInitiated: true)->afterCommit();
    }

    /**
     * Square (2026-09-02): no salon scrape to run, and no row id needed — the
     * job resolves the connection itself and rewrites the URL in place.
     */
    private function dispatchSquareAutoSelect(string $userId): void
    {
        SquareAutoSelectJob::dispatch($userId)->afterCommit();
    }

    /**
     * Install-wide daily ceiling on auto-triggered salon scrapes.
     *
     * Mirrors partna.routing.probe's global_daily_cap and exists for the same
     * reason: an unbounded outbound request the backend makes on a user's say-so
     * is a reliability risk to us and an amplification vector aimed at someone
     * else. Shared by every producer, so the ceiling is genuinely install-wide
     * rather than one budget per discovery route.
     *
     * Claims through DailyCounterClaim rather than a private counter: a
     * hand-rolled `Cache::add` + `increment` is two round trips, and if the key
     * expires between them INCRBY recreates it with NO TTL — permanent
     * inevictable ballast under instance-wide volatile-lru.
     *
     * Fails OPEN on a throw: a broken counter must not silently stop every
     * pre-account build from acquiring its services.
     */
    public function claimBudget(): bool
    {
        $cap = (int) config('partna.connect.auto_booking.global_daily_cap', 500);

        try {
            if (! DailyCounterClaim::claim(CacheKeyGenerator::freshaAutoConnectDaily(now()->format('Y-m-d')), $cap)) {
                Log::warning('fresha.auto_connect.daily_cap_reached', ['cap' => $cap]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }
}
