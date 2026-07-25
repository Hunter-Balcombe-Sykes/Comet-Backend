<?php

namespace App\Services\FeatureFlags;

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\User\User;
use App\Services\Cache\CacheLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves feature flag state for a given professional context.
 *
 * Resolution order (highest → lowest precedence; the brand-scoped override tier
 * that used to sit at #2 was removed with the brand→individual pivot):
 *   1. Professional-scoped override
 *   3. Percentage rollout — deterministic hash(key + pro.id) % 100
 *   4. Registry default (feature_flags.default_enabled)
 *   5. Config fallback — config('partna.features.{key}', false)
 *
 * All lookups are served from Redis (via CacheLockService) with a 5-minute TTL
 * and ±20% jitter (applied by CacheLockService, not here — see CCH-2). Two cache keys are used per context:
 *   - ff:registry          — all FeatureFlag rows (defaults + rollout %)
 *   - ff:pro:{proId}       — all active overrides for a professional
 *
 * setOverride/clearOverride flush the relevant pro key so the next
 * read rebuilds from DB immediately (push invalidation).
 *
 * On any cache failure, falls back to direct DB queries and logs a warning.
 */
class FeatureFlagService
{
    private const BASE_TTL_SECONDS = 300;

    private const REGISTRY_KEY = 'ff:registry';

    /** @var array<string, array> Request-scoped memoization of loaded flag arrays, keyed by proId. */
    private array $requestCache = [];

    public function __construct(private CacheLockService $cacheLock) {}

    public function enabled(string $key, ?User $pro = null): bool
    {
        try {
            [$registry, $proOverrides] = $this->loadAll($pro);

            return $this->resolveFromArrays($key, $registry, $proOverrides, $pro);
        } catch (Throwable $e) {
            Log::warning('feature_flags.cache_unavailable', [
                'error' => $e->getMessage(),
                'flag_key' => $key,
                'user_id' => $pro?->id,
                'request_id' => request()?->header('X-Request-Id'),
            ]);

            $result = $this->allForFromDb($pro);

            // Use array access on the features sub-array to prevent a dotted $key from
            // traversing into nested config unexpectedly via dot-path interpolation.
            return $result[$key] ?? (bool) (config('partna.features')[$key] ?? false);
        }
    }

    /**
     * Return the full ['key' => bool, ...] map for the given scope.
     */
    public function allFor(?User $pro = null): array
    {
        try {
            [$registry, $proOverrides] = $this->loadAll($pro);
        } catch (Throwable $e) {
            Log::warning('feature_flags.cache_unavailable', [
                'error' => $e->getMessage(),
                'method' => 'allFor',
                'user_id' => $pro?->id,
                'request_id' => request()?->header('X-Request-Id'),
            ]);

            return $this->allForFromDb($pro);
        }

        $result = [];
        foreach (array_keys($registry) as $k) {
            $result[$k] = $this->resolveFromArrays($k, $registry, $proOverrides, $pro);
        }

        return $result;
    }

    /**
     * Upsert a feature flag override for the given scope, then invalidate
     * the relevant cache key so the next read reflects the change.
     *
     * Returns the upserted row itself (not just a bool) so callers never
     * re-read it — a re-read whose predicates drifted from this method's
     * 500'd the staff override endpoint for two months (#FFLAG-1).
     *
     * @return OverrideResult the upserted row + whether push-invalidation succeeded
     */
    public function setOverride(
        string $key,
        OverrideScope $scope,
        bool $enabled,
        ?string $reason = null,
        ?Carbon $expiresAt = null,
        ?string $createdBy = null,
    ): OverrideResult {
        $attrs = [
            'flag_key' => $key,
            'enabled' => $enabled,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ];

        $override = FeatureFlagOverride::updateOrCreate(
            ['flag_key' => $key, 'user_id' => $scope->userId],
            $attrs + ['user_id' => $scope->userId],
        );

        // Bust request-level memoization so any subsequent enabled() calls in
        // this request see the new override rather than the now-stale memoized tuple.
        $this->requestCache = [];

        try {
            $this->forgetPro($scope->userId);

            return new OverrideResult($override, true);
        } catch (Throwable $e) {
            Log::warning('feature_flags.invalidation_failed', [
                'error' => $e->getMessage(),
                'flag_key' => $key,
                'scope_user_id' => $scope->userId,
            ]);

            return new OverrideResult($override, false);
        }
    }

    /**
     * Delete a feature flag override for the given scope, then invalidate
     * the relevant cache key.
     *
     * @return bool true if cache invalidation succeeded, false if it failed (DB delete always succeeds)
     */
    public function clearOverride(string $key, OverrideScope $scope): bool
    {
        FeatureFlagOverride::where('flag_key', $key)
            ->where('user_id', $scope->userId)
            ->delete();

        $this->requestCache = [];

        try {
            $this->forgetPro($scope->userId);

            return true;
        } catch (Throwable $e) {
            Log::warning('feature_flags.invalidation_failed', [
                'error' => $e->getMessage(),
                'flag_key' => $key,
                'scope_user_id' => $scope->userId,
            ]);

            return false;
        }
    }

    /** Flush only the registry key (use after adding/editing a FeatureFlag row). */
    public function flushRegistry(): void
    {
        $this->requestCache = [];
        Cache::forget(self::REGISTRY_KEY);
        Cache::forget(self::REGISTRY_KEY.':stale');
    }

    /**
     * Flush the registry cache key. Per-pro override keys (ff:pro:{id})
     * are NOT flushed here — call forgetPro to invalidate individual scopes.
     *
     * SWR stale copies persist up to ~60 min worst case; natural expiry is the only guarantee.
     * Worst case math: base TTL 300s (un-jittered here since CCH-2), then
     * CacheLockService::writeWithJitter applies ±20% to (input × STALE_TTL_MULTIPLIER=10),
     * so the stale key's TTL caps at round(300 × 10 × 1.2) = 3600s = 60 min.
     */
    public function flush(): void
    {
        $this->flushRegistry();
    }

    public function forgetPro(string $proId): void
    {
        Cache::forget("ff:pro:{$proId}");
        Cache::forget("ff:pro:{$proId}:stale");
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function loadAll(?User $pro): array
    {
        $cacheKey = $pro?->id ?? 'null';

        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }

        $registry = $this->loadRegistry();
        $proOverrides = $pro !== null ? $this->loadProOverrides($pro->id) : [];

        return $this->requestCache[$cacheKey] = [$registry, $proOverrides];
    }

    /**
     * Resolve a single flag key against pre-loaded arrays (no DB/cache I/O).
     *
     * @param  array<string, array{default_enabled: bool, rollout_percent: int}>  $registry
     * @param  array<string, bool>  $proOverrides
     */
    private function resolveFromArrays(
        string $key,
        array $registry,
        array $proOverrides,
        ?User $pro,
    ): bool {
        // 1. Pro override. (#2, brand-scoped override, was removed with the brand pivot.)
        if (isset($proOverrides[$key])) {
            return $proOverrides[$key];
        }

        $flag = $registry[$key] ?? null;

        // 3. Percentage rollout — deterministic: same pro+key always lands in the same bucket.
        //    abs() guards against negative crc32 values on 64-bit PHP.
        if ($flag !== null && $pro !== null && $flag['rollout_percent'] > 0) {
            if ((abs(crc32($key.$pro->id)) % 100) < $flag['rollout_percent']) {
                return true;
            }
        }

        // 4. Global registry default.
        if ($flag !== null) {
            return $flag['default_enabled'];
        }

        // 5. Config fallback — used for flags that don't yet have a DB row.
        // Use array access on the features sub-array to prevent a dotted $key from
        // traversing into nested config unexpectedly via dot-path interpolation.
        return (bool) (config('partna.features')[$key] ?? false);
    }

    /**
     * Compute the cache TTL, optionally capped by an override's expires_at.
     *
     * Returns an int (seconds) for the normal path so CacheLockService applies its
     * ±20% jitter. When an override's expires_at is sooner than the base TTL, returns
     * the Carbon deadline directly — CacheLockService::writeWithJitter honours
     * DateTimeInterface TTLs verbatim (no re-jitter), so the primary key can never
     * be served past expires_at. Without this hand-off, the int path would be
     * re-jittered ±20% and could leak ~20% past the cap.
     */
    private function jitteredTtl(?Carbon $nearestExpiry = null): Carbon|int
    {
        // CCH-2: return the base TTL un-jittered. CacheLockService applies the single
        // ±20% jitter to every int TTL it writes; adding jitter here too double-jittered it.
        $base = self::BASE_TTL_SECONDS;

        if ($nearestExpiry !== null) {
            $secondsUntilExpiry = max(1, (int) $nearestExpiry->diffInSeconds(now(), false) * -1);

            if ($secondsUntilExpiry < $base) {
                return $nearestExpiry;
            }
        }

        return $base;
    }

    /** Load all active FeatureFlag rows as a key-indexed map. */
    private function loadRegistry(): array
    {
        return $this->cacheLock->rememberLocked(
            self::REGISTRY_KEY,
            $this->jitteredTtl(),
            function (): array {
                return FeatureFlag::query()
                    ->whereNull('deleted_at')
                    ->get()
                    ->mapWithKeys(fn ($f) => [$f->key => [
                        'default_enabled' => (bool) $f->default_enabled,
                        'rollout_percent' => (int) $f->rollout_percent,
                    ]])
                    ->all();
            },
        );
    }

    /**
     * Load all non-expired, pro-scoped overrides for $proId as a flag_key → bool map.
     *
     * The whereExists filter guards against returning overrides for soft-deleted
     * professionals. Skipped in SQLite test environment (schema-qualified table names
     * aren't supported there, but soft-deletes don't apply in tests anyway).
     */
    private function loadProOverrides(string $proId): array
    {
        $key = "ff:pro:{$proId}";

        // Warm-read fast path: skip the MIN(expires_at) query when the primary
        // cache key is hot. The MIN is only needed to cap the TTL on a write,
        // which happens on miss/SWR. rememberLocked() will re-check Cache::get
        // internally — a second Redis GET (~0.1ms) is cheaper than a Postgres
        // round-trip on every warm read.
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // Determine nearest expiry before entering the lock so the TTL can be
        // capped — prevents overrides from being served past their expires_at.
        $nearestExpiry = FeatureFlagOverride::query()
            ->where('user_id', $proId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->min('expires_at');

        $nearestExpiry = $nearestExpiry ? Carbon::parse($nearestExpiry) : null;

        return $this->cacheLock->rememberLocked(
            $key,
            $this->jitteredTtl($nearestExpiry),
            function () use ($proId): array {
                $query = FeatureFlagOverride::query()
                    ->where('user_id', $proId)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

                // Only apply the cross-schema soft-delete joins on PostgreSQL — SQLite
                // (used in tests) doesn't support schema-qualified table names.
                if (DB::getDriverName() === 'pgsql') {
                    $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('core.users')
                        ->whereColumn('core.users.id', 'core.feature_flag_overrides.user_id')
                        ->whereNull('core.users.deleted_at'));

                    // Exclude overrides whose flag has been soft-deleted.
                    $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('core.feature_flags')
                        ->whereColumn('core.feature_flags.key', 'core.feature_flag_overrides.flag_key')
                        ->whereNull('core.feature_flags.deleted_at'));
                }

                return $query->get()
                    ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                    ->all();
            },
        );
    }

    /**
     * Batch DB fallback for allFor() when cache is unavailable.
     * Loads registry + pro overrides in 2 queries, then resolves the full map
     * from arrays — no N+1 per flag key.
     */
    private function allForFromDb(?User $pro): array
    {
        $registry = FeatureFlag::query()
            ->whereNull('deleted_at')
            ->get()
            ->mapWithKeys(fn ($f) => [$f->key => [
                'default_enabled' => (bool) $f->default_enabled,
                'rollout_percent' => (int) $f->rollout_percent,
            ]])
            ->all();

        $proOverrides = [];
        if ($pro !== null) {
            $query = FeatureFlagOverride::query()
                ->where('user_id', $pro->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

            if (DB::getDriverName() === 'pgsql') {
                $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('core.users')
                    ->whereColumn('core.users.id', 'core.feature_flag_overrides.user_id')
                    ->whereNull('core.users.deleted_at'));

                $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('core.feature_flags')
                    ->whereColumn('core.feature_flags.key', 'core.feature_flag_overrides.flag_key')
                    ->whereNull('core.feature_flags.deleted_at'));
            }

            $proOverrides = $query->get()
                ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                ->all();
        }

        $result = [];
        foreach (array_keys($registry) as $k) {
            $result[$k] = $this->resolveFromArrays($k, $registry, $proOverrides, $pro);
        }

        return $result;
    }
}
