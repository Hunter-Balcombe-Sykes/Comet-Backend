<?php

namespace App\Catalog;

use App\Catalog\Concerns\IgnoresMissingRelation;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The staff kill-switch for a single detector: catalog.detector_suspensions.
 *
 * Suspending a detector takes it out of the running for every URL without
 * recompiling the catalog or shipping code — the answer to "this rule is
 * placing the wrong brand and I need it off NOW". The compiled artefact stays
 * the source of truth for what a detector IS; this is the runtime override for
 * whether it currently RUNS, which is why `catalog:sync` never touches the
 * table and a recompile cannot resurrect a suspension.
 *
 * The set is resolved once per request when the container builds the Rulepack
 * singleton (AppServiceProvider) and carried as data on the pack, so
 * LinkProjector::project() keeps its no-I/O contract.
 *
 * Operated through `catalog:suspend-detector` / `--release`. There is no HTTP
 * surface deliberately: this is an operator control, and an artisan command
 * inherits the same access boundary as `catalog:sync` without needing a policy,
 * an AAL2 gate and a staff route to argue about.
 */
class DetectorSuspensions
{
    use IgnoresMissingRelation;

    public const CACHE_KEY = 'catalog:detector-suspensions';

    private const TABLE = 'catalog.detector_suspensions';

    /**
     * Detector ids currently suspended, ordered for a stable set.
     *
     * FAIL-OPEN. Production has no `catalog` schema at all, so this query
     * throws there on every call. A kill-switch whose lookup can 500 the paste
     * preview is a worse failure than the detector it exists to disable, so a
     * broken read degrades to "nothing is suspended" and says so in the log.
     * The trade is stated rather than hidden: while the read is broken, a
     * suspended detector is live.
     *
     * @return list<string>
     */
    public function active(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, $this->ttlSeconds(), fn () => $this->read());
        } catch (Throwable $e) {
            // Absence is NOT a fault. Production has no catalog schema at all,
            // so warning here would fire on every Rulepack build, forever, for
            // a state CLAUDE.md documents as intended — and a warning nobody
            // can act on is how the ones that matter become invisible. Any
            // OTHER failure is a real fault and still gets said out loud.
            if (! $this->isMissingRelation($e)) {
                Log::warning('catalog.detector_suspensions.read_failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }
    }

    /**
     * Suspend a detector until $expiresAt, or extend an existing suspension.
     *
     * detector_id is the primary key, so re-suspending replaces the window
     * rather than colliding — "still broken, give it another day" is the
     * ordinary second call.
     */
    public function suspend(string $detectorId, string $reason, ?string $setBy, DateTimeInterface $expiresAt): void
    {
        DB::connection('pgsql')->table(self::TABLE)->upsert([
            [
                'detector_id' => $detectorId,
                'reason' => $reason,
                'set_by' => $setBy,
                'set_at' => now(),
                'expires_at' => $expiresAt,
            ],
        ], ['detector_id'], ['reason', 'set_by', 'set_at', 'expires_at']);

        $this->forget();
    }

    /** @return bool whether a suspension was actually lifted */
    public function release(string $detectorId): bool
    {
        $deleted = DB::connection('pgsql')->table(self::TABLE)
            ->where('detector_id', $detectorId)
            ->delete();

        $this->forget();

        return $deleted > 0;
    }

    /**
     * Every live suspension, for the operator listing.
     *
     * @return list<object>
     */
    public function listActive(): array
    {
        return DB::connection('pgsql')->table(self::TABLE)
            ->where('expires_at', '>', now())
            ->orderBy('detector_id')
            ->get()
            ->all();
    }

    /**
     * Drop the memo so a suspension takes effect on the next request rather
     * than at the end of the TTL — which is exactly when someone is watching.
     */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable $e) {
            // A dead cache means active() is already failing open; losing the
            // invalidation on top of that changes nothing and must not turn a
            // successful write into an error.
            Log::warning('catalog.detector_suspensions.forget_failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<string> */
    private function read(): array
    {
        return DB::connection('pgsql')->table(self::TABLE)
            ->where('expires_at', '>', now())
            ->orderBy('detector_id')
            ->pluck('detector_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Floored at 1: a non-positive TTL makes Cache::remember() treat the write
     * as already expired, so every request would re-query — the opposite of
     * what a "0 = no caching" reading intends. Also bounds how long a released
     * detector could stay dark if an invalidation is ever lost.
     */
    private function ttlSeconds(): int
    {
        return max(1, (int) config('partna.catalog.suspension_cache_ttl_seconds', 60));
    }
}
