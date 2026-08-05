<?php

namespace App\Services\FeatureAvailability;

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\User\User;
use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Cache\CacheLockService;
use App\Services\Segments\SegmentResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read side for core.feature_availability — answers "is feature X available to
 * this user right now?".
 *
 * THE PATTERN (for any feature-gated surface):
 *
 *     if (FeatureAvailability::for($user)->allows('integration.instagram')) { ... }
 *
 * Resolution per feature key:
 *   1. no rule rows at all               → AVAILABLE (absence = enabled)
 *   2. segment-scoped rules the user belongs to BEAT the global rule
 *   3. several matching segment rules    → 'disabled' wins (deny wins)
 *   4. otherwise the global rule's mode.
 *
 * Key convention: 'integration.<platform>' (consulted by the /platforms/meta
 * integrations-config endpoint), 'feature.<name>' for other surfaces.
 *
 * The whole rule table is small and staff-curated, so the per-user snapshot is
 * computed in one pass and cached for 60s; staff CRUD calls flush().
 *
 * Fail-open on a DB fault is deliberate and load-bearing (public submit
 * endpoints, platform-connect middleware, the "my site" payload all read
 * through this) — never make it fail-closed. The fault sentinel is kept OFF
 * the primary (jittered + stale-while-revalidate) cache key though: see
 * for()'s catch block.
 */
final class FeatureAvailability
{
    use EscalatesRepeatedFaults;

    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_VERSION_KEY = 'feature-availability:version';

    /**
     * TTL for the fail-open sentinel written when resolveOverrides() faults.
     * Deliberately short and on its OWN key, never the primary one: the
     * primary path is jittered (~48-72s) with a 10x stale-while-revalidate
     * copy (CacheLockService), so a transient DB fault cached there would
     * silently open every feature gate for up to ~10 minutes. A short,
     * dedicated key single-flights a request storm during the blip and
     * retries the DB again within seconds of it clearing.
     */
    private const FAILOPEN_TTL_SECONDS = 5;

    public static function for(User $user): UserFeatureAvailability
    {
        $lock = app(CacheLockService::class);
        $failopenKey = "feature-availability:failopen:{$user->id}";

        // These two reads USED TO SIT OUTSIDE any try. That was a
        // guard-one-line-too-late bug: the try below was written for a DB fault,
        // under which these two lines are fine — but under a CACHE fault they are
        // the first thing to throw, and they threw straight past the fail-open
        // handler written to absorb exactly this. Drill 03 (2026-08-05) caught it
        // as a raw 500 on GET /api/site during a Redis outage.
        //
        // Both values are optimisations, not inputs: the sentinel only avoids
        // re-faulting on a query we are about to fail open on anyway, and the
        // version token only namespaces the cache key. Neither is worth a 500.
        try {
            // A DB fault very recently parked a short-lived sentinel here (see the
            // catch below) — skip straight to fail-open instead of re-attempting
            // (and re-faulting on) the same query on every request during a blip.
            if (Cache::get($failopenKey) !== null) {
                return new UserFeatureAvailability([]);
            }

            $version = (int) Cache::get(self::CACHE_VERSION_KEY, 0);
        } catch (Throwable $e) {
            // Store unreachable. DO NOT fail open here.
            //
            // It is tempting to return an empty override set and be done — an
            // earlier draft of this fix did exactly that, reasoning that
            // rememberLocked() below would fault on the same store anyway. That
            // reasoning is FALSE and the mistake is expensive: rememberLocked()
            // catches store faults itself and falls through to
            // computeWithoutCache(), which runs the query directly against
            // Postgres (CacheLockService::rememberLocked ~:106 →
            // computeWithoutCache ~:351). With a dead cache and a healthy DB it
            // returns the TRUE rule set.
            //
            // Failing open here would therefore silently re-enable every
            // staff-disabled feature and integration — including kill-switches
            // pulled for legal reasons — for the whole duration of a Valkey blip,
            // while the authoritative answer sat one healthy query away.
            //
            // Guarded: config/logging.php's `stack` sets 'ignore_exceptions' => false
            // and LOG_STACK includes nightwatch, so a broken handler here would throw
            // out of the very catch that exists to stop this path 500ing.
            // escalateIfSustained is already catch-all internally.
            try {
                Log::warning('feature_availability.cache_unavailable', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Breadcrumb lost; the resolve below is unaffected.
            }
            self::escalateIfSustained($e, 'cache_unavailable');

            // DO NOT guess a namespace and fall through. `$version = 0` reads as
            // harmless — "a dead store has no entry under any version" — and a
            // previous revision of this method did exactly that. It is wrong,
            // because this catch does not establish that the store is DEAD. It
            // fires on ANY throwable from either read, including the transient
            // shape documented in GuardedPhpRedisConnection:52-54: "op 1 fails,
            // Laravel reconnects, and ops 2..N succeed". Then rememberLocked()
            // reads the LIVE `:v0` key while the real version is N, and serves a
            // pre-flush snapshot — silently lifting a staff kill-switch, the exact
            // outcome this method was rewritten to prevent.
            //
            // That is not theoretical: ArmRedisRequestBreaker deliberately never
            // arms outside HTTP (its docblock), and for() is called from
            // ConnectFetchJob, ShopBrandConnectJob, FreshaConnectFetch and
            // CustomLinkSeeder — all queue paths, where nothing stops op 2
            // succeeding.
            //
            // Resolve directly: no namespace, no cache, one query. Which is what
            // computeWithoutCache() would have done for us anyway.
            try {
                return new UserFeatureAvailability(self::resolveOverrides($user));
            } catch (Throwable $dbEx) {
                // Cache unreachable AND the query failed — now we genuinely cannot
                // know, so the absence-==-enabled fail-open applies. No sentinel
                // write: the store that would hold it is the one that just failed.
                try {
                    Log::warning('feature_availability.resolve_overrides_failed', [
                        'user_id' => $user->id,
                        'error' => $dbEx->getMessage(),
                    ]);
                } catch (Throwable) {
                    // Breadcrumb lost; the fail-open below is unaffected.
                }
                self::escalateIfSustained($dbEx, 'resolve_overrides');

                return new UserFeatureAvailability([]);
            }
        }

        // Single-flight via CacheLockService — without this, a staff flush() bumping
        // the version token cold-misses every concurrent reader at once and they all
        // race to recompute (`for()` is static, so the lock service is resolved from
        // the container rather than injected).
        try {
            $overrides = $lock->rememberLocked(
                "feature-availability:user:{$user->id}:v{$version}",
                self::CACHE_TTL_SECONDS,
                fn () => self::resolveOverrides($user),
            );
        } catch (Throwable $e) {
            // resolveOverrides() lets DB faults bubble (see its docblock) instead of
            // swallowing them into an "everything available" array that rememberLocked
            // can't distinguish from real data and would cache for the full primary
            // TTL. Fail open here too — same absence-=-enabled contract — but via the
            // dedicated short-TTL sentinel above, never the primary key. Escalate on a
            // sustained run (a single blip stays a Log::warning breadcrumb).
            // Guarded for the same reason as the cache catch above: the `stack`
            // channel has 'ignore_exceptions' => false, so a broken log handler
            // would throw out of a catch whose whole contract is that nothing
            // escapes it.
            try {
                Log::warning('feature_availability.resolve_overrides_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Breadcrumb lost; the fail-open below is unaffected.
            }
            self::escalateIfSustained($e, 'resolve_overrides');

            // Writing the sentinel is a courtesy to the NEXT request, not part of
            // serving this one. rememberLockedNullable() already absorbs read and
            // lock faults internally, so this guard is belt-and-braces rather than
            // a live failure mode — kept because this catch's contract is that
            // NOTHING escapes it, and that should not depend on the internals of a
            // collaborator. Losing the sentinel only costs a repeated fault.
            try {
                $lock->rememberLockedNullable($failopenKey, self::FAILOPEN_TTL_SECONDS, fn () => null);
            } catch (Throwable) {
                // Sentinel unwritable — the fail-open return below is unaffected.
            }

            return new UserFeatureAvailability([]);
        }

        return new UserFeatureAvailability($overrides);
    }

    /** Staff CRUD calls this after every write so reads reflect changes immediately. */
    public static function flush(): void
    {
        Cache::increment(self::CACHE_VERSION_KEY);
    }

    /**
     * Compute the user's non-default availability map. Only keys with an
     * applicable rule appear; everything else defaults to available.
     *
     * Deliberately does NOT catch here (CCH-5): a DB fault must bubble to
     * for()'s caller so it can fail open via the dedicated short-TTL sentinel
     * instead of getting cached as valid data on the primary (jittered + SWR)
     * key for the full TTL. This also covers SQLite test mirrors without the
     * table — same bubble-and-fail-open path.
     *
     * @return array<string, bool> feature_key => allowed
     */
    private static function resolveOverrides(User $user): array
    {
        $rules = FeatureAvailabilityRule::query()->get(['feature_key', 'mode', 'segment_id']);

        if ($rules->isEmpty()) {
            return [];
        }

        // Resolve segment membership only for segments that actually carry rules.
        $segmentIds = $rules->pluck('segment_id')->filter()->unique()->values();
        $memberOf = [];
        if ($segmentIds->isNotEmpty()) {
            $resolver = app(SegmentResolver::class);
            foreach (UserSegment::query()->whereIn('id', $segmentIds)->get() as $segment) {
                if (in_array((string) $user->id, $resolver->userIds($segment), true)) {
                    $memberOf[(string) $segment->id] = true;
                }
            }
        }

        $map = [];
        foreach ($rules->groupBy('feature_key') as $key => $keyRules) {
            $segmentModes = [];
            $globalMode = null;

            foreach ($keyRules as $rule) {
                if ($rule->segment_id === null) {
                    $globalMode = $rule->mode;
                } elseif (isset($memberOf[(string) $rule->segment_id])) {
                    $segmentModes[] = $rule->mode;
                }
            }

            if ($segmentModes !== []) {
                // Segment rules beat global; deny wins across segments.
                $map[(string) $key] = ! in_array(FeatureAvailabilityRule::MODE_DISABLED, $segmentModes, true);
            } elseif ($globalMode !== null) {
                $map[(string) $key] = $globalMode === FeatureAvailabilityRule::MODE_ENABLED;
            }
        }

        return $map;
    }
}
