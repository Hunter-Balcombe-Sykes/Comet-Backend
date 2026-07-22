<?php

namespace App\Http\Middleware;

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class IdempotencyKey
{
    use JitteredTtl;

    // TTL_SEC / LOCK_SEC / MAX_BODY_BYTES live in config/partna.php (idempotency.*)
    // so they're tunable without a redeploy — read via config() at each call site below.

    // Headers that must never be cached/replayed — Symfony's HttpKernel::filterResponse
    // sets these at send time (Date) or via prepare() (Content-Length, Transfer-Encoding).
    // Replaying a stale Date is harmless but cluttered; replaying a stale Content-Length
    // breaks responses whose body is regenerated on the way out.
    private const HEADER_REPLAY_BLOCKLIST = ['date', 'content-length', 'transfer-encoding', 'connection'];

    // Strict UUID v4: literal '4' version nibble + [89ab] variant nibble.
    // Rejects v1 (time/MAC), v3 (md5), v5 (sha1) — only v4 provides the
    // randomness expected of an idempotency key.
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        // GET/HEAD/OPTIONS are HTTP-spec idempotent already; caching them
        // here would conflict with AddETagHeaders / AddPublicCacheHeaders.
        if (! in_array($request->method(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        if (! preg_match(self::UUID_V4_PATTERN, $key)) {
            return response()->json([
                'message' => 'Idempotency-Key must be a UUID v4.',
                'code' => 'idempotency_invalid_key',
            ], 400);
        }

        $userId = (string) $request->attributes->get('supabase_uid', '');
        if ($userId === '') {
            return $next($request);
        }

        $route = $this->routeScope($request);
        $version = $this->appVersion();
        $cacheKey = $this->cacheKey($version, $userId, $route, $key);

        // Fast path — completed response already cached.
        // Fail-open: if the cache layer is unreachable, log + proceed
        // without idempotency rather than 503-ing every mutating request.
        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable $e) {
            $this->logFailOpen($e, 'lookup', $request);

            return $next($request);
        }
        if ($this->isValidCacheEntry($cached)) {
            return $this->rebuildResponse($cached);
        }

        // Slow path — acquire a distributed lock so two concurrent identical
        // requests don't both execute the handler. Lock lives on the
        // `cache_locks` Redis connection in production (per config/cache.php
        // lock_connection), isolated from data-cache flushes.
        try {
            // distributed lock TTL — sized for slow synchronous handlers (mail dispatch,
            // R2 upload). Raise further only if a handler legitimately exceeds 2 min.
            $lockSeconds = (int) config('partna.idempotency.lock_seconds', 120);
            $lock = Cache::lock($this->lockKey($version, $userId, $route, $key), $lockSeconds);
            $acquired = $lock->get();
        } catch (Throwable $e) {
            $this->logFailOpen($e, 'lock', $request);

            return $next($request);
        }

        if (! $acquired) {
            // Another request with the same key is mid-flight. Tell the client
            // to retry shortly — they should hit the cache fast-path next time.
            return response()->json([
                'message' => 'Request with the same Idempotency-Key is already in progress.',
                'code' => 'idempotency_locked',
            ], 409, ['Retry-After' => '1']);
        }

        try {
            // Re-check the cache under the lock — the request that held the
            // lock just before us may have completed between our cache miss
            // and our lock acquire.
            try {
                $rechecked = Cache::get($cacheKey);
                if ($this->isValidCacheEntry($rechecked)) {
                    return $this->rebuildResponse($rechecked);
                }
            } catch (Throwable $e) {
                // OBS-1: route through the same fail-open observability as every other
                // Redis touchpoint below — a sustained outage here was previously silent.
                // Behavior is unchanged: still fall through and run the handler.
                $this->logFailOpen($e, 're-check', $request);
            }

            $response = $next($request);

            if ($this->shouldCache($response)) {
                try {
                    // CCH-1: shared JitteredTtl helper (±20% via mt_rand) applied to the
                    // config-driven TTL, so a burst of same-second writes don't all expire
                    // on the same tick (matches the caching gold standard used elsewhere —
                    // SiteCacheService/CacheLockService/ShopController).
                    $ttl = self::applyJitter((int) config('partna.idempotency.ttl_seconds', 86_400));
                    Cache::put($cacheKey, [
                        'v' => 1, // schema version — bump if the payload shape ever changes
                        'status' => $response->getStatusCode(),
                        'body' => $response->getContent(),
                        'headers' => $this->captureHeaders($response),
                    ], $ttl);

                    // PRIV-1: index this cache key under the user so AccountDeletionService
                    // can flush every cached response for them at deletion-confirm time —
                    // see indexCacheKeyForUser() for why. Self-contained try/catch inside,
                    // so a failure here can't be blamed on the Cache::put above.
                    $this->indexCacheKeyForUser($userId, $cacheKey, $request);
                } catch (Throwable $e) {
                    // Handler already ran — don't lose the response just because
                    // we couldn't cache it. Log and return the live response.
                    $this->logFailOpen($e, 'store', $request);
                }
            }

            return $response;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock auto-expires on TTL; release-after-expiry can throw on
                // some drivers. Swallow — the lock has done its job either way.
            }
        }
    }

    private function logFailOpen(Throwable $e, string $stage, Request $request): void
    {
        Log::warning('Idempotency middleware failing open', [
            'stage' => $stage,
            'reason' => $e->getMessage(),
            'operation' => __METHOD__,
            // LIFE-2: correlate to a user/request during an incident.
            'user_id' => $request->attributes->get('supabase_uid'),
            'request_id' => $request->header('X-Request-Id'),
        ]);

        // report() — the Log::warning above is breadcrumb-only. Throttled to one per
        // minute via the isolated cache_locks connection (mirrors VerifySupabaseJwt::jwksOutage)
        // so a sustained Redis outage can't flood Nightwatch; report anyway if the
        // throttle layer is itself unreachable.
        try {
            $lock = Cache::lock('idempotency:fail-open-reported', 60);
            if ($lock->get()) {
                report($e);
            }
        } catch (Throwable) {
            report($e);
        }
    }

    private function cacheKey(string $version, string $userId, string $route, string $key): string
    {
        return "idempotency:resp:{$version}:{$userId}:{$route}:{$key}";
    }

    /**
     * PRIV-1 (GDPR): record $cacheKey in a per-user Redis SET so
     * AccountDeletionService::purgeIdempotencyCache() can find and delete every
     * response-cache entry for a user at deletion-confirm time, rather than
     * letting PII linger for the full 24h TTL after account deletion.
     *
     * Stores the app-level $cacheKey string (not a physically-prefixed key) —
     * deletion reads it back and calls Cache::forget($cacheKey), which applies
     * Laravel's cache prefix itself.
     *
     * Same Redis connection/DB as the 'redis' cache store (config/cache.php ->
     * config/database.php redis.cache, DB1) so this index and the response
     * cache it tracks live side by side. Best-effort: a Redis failure here must
     * never break the request — the response was already cached (or the caller
     * already handled a Cache::put failure) by the time we get here.
     */
    private function indexCacheKeyForUser(string $userId, string $cacheKey, Request $request): void
    {
        try {
            $indexKey = $this->userIndexKey($userId);
            $connection = Redis::connection('cache');
            $connection->sadd($indexKey, $cacheKey);

            // Index TTL is sized off the CONFIGURED base TTL (not this entry's
            // own jittered $ttl) inflated by JitteredTtl's max ×1.2 multiplier,
            // so it always outlives every entry it could ever reference —
            // regardless of that entry's own jitter draw. Refreshed on every
            // write so the index keeps sliding forward while the user is active.
            $baseTtl = (int) config('partna.idempotency.ttl_seconds', 86_400);
            $connection->expire($indexKey, (int) ceil($baseTtl * 1.2));
        } catch (Throwable $e) {
            $this->logFailOpen($e, 'index', $request);
        }
    }

    private function userIndexKey(string $userId): string
    {
        return CacheKeyGenerator::idempotencyIndexKey($userId);
    }

    private function lockKey(string $version, string $userId, string $route, string $key): string
    {
        return "idempotency:lock:{$version}:{$userId}:{$route}:{$key}";
    }

    /**
     * App-version namespace — a deploy that changes a response shape bumps this
     * prefix so the new code doesn't replay stale-shape JSON from before-deploy
     * keys. Falls back to a fixed string if APP_VERSION is unset so cache keys
     * remain stable in environments that don't set it.
     */
    private function appVersion(): string
    {
        $version = (string) config('app.version', '');

        return $version !== '' ? $version : 'v0';
    }

    private function routeScope(Request $request): string
    {
        // Method is included in BOTH branches: named-route reuse across methods
        // is legal in Laravel (last-wins), and the unnamed fallback already had
        // this disambiguation. Keep them symmetric so future named routes can't
        // cross-pollute even if they share a name across HTTP methods.
        $method = $request->method();
        $name = $request->route()?->getName();
        if (is_string($name) && $name !== '') {
            return $method.':'.$name;
        }

        return 'unnamed:'.sha1($method.' '.$request->getPathInfo());
    }

    private function shouldCache(Response $response): bool
    {
        // 5xx responses signal transient infra failures (DB down, timeout) —
        // never cache them, so a retry against a recovered backend can succeed.
        if ($response->getStatusCode() >= 500) {
            return false;
        }

        // Streamed bodies are consumed as the response is sent; there's no
        // captured string we could replay. Same for file downloads — replaying
        // a stale file payload would be wrong even if we could serialize it.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        // Cap on body size so an endpoint that accidentally returns a huge
        // payload can't ratchet Redis memory by being called with many keys.
        $body = $response->getContent();
        if (! is_string($body) || strlen($body) > (int) config('partna.idempotency.max_body_bytes', 262_144)) {
            return false;
        }

        return true;
    }

    private function rebuildResponse(array $payload): Response
    {
        $response = new Response(
            (string) ($payload['body'] ?? ''),
            (int) ($payload['status'] ?? 200),
        );

        // Restore every header captured at cache time (Content-Type, Set-Cookie,
        // Location, ETag, X-RateLimit-*, custom X-* headers). Without this, the
        // replay would strip every header the controller or inner middleware
        // attached and only keep Content-Type — silently breaking any caller
        // that depends on a response header on retry.
        foreach ((array) ($payload['headers'] ?? []) as $name => $values) {
            if (! is_string($name)) {
                continue;
            }
            $response->headers->set($name, (array) $values, true);
        }

        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    /**
     * Capture headers set by the controller and inner middleware (ThrottleRequests,
     * AddETagHeaders, AddPublicCacheHeaders, anything that ran before us on the
     * way out). Drops headers Symfony will (re-)set at send time so we don't
     * replay stale Content-Length or Date.
     *
     * @return array<string, array<int, string|null>>
     */
    private function captureHeaders(Response $response): array
    {
        $headers = $response->headers->all();
        foreach (self::HEADER_REPLAY_BLOCKLIST as $drop) {
            unset($headers[$drop]);
        }

        return $headers;
    }

    /**
     * Cache-shape guard. Rejects payloads missing the required keys — defends
     * against partial writes, manual cache poisoning, and stale entries from
     * an older middleware version that lacked the schema-version field.
     */
    private function isValidCacheEntry(mixed $cached): bool
    {
        return is_array($cached)
            && isset($cached['status'], $cached['body'])
            && (($cached['v'] ?? null) === 1);
    }
}
