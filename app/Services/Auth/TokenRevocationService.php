<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Supabase session_id blocklist backed by Redis.
 *
 * Supabase JWTs carry a stable `session_id` claim that survives token
 * refreshes — that's the identifier we revoke, not the per-refresh JTI.
 * Revoking a session_id terminates every access token issued under that
 * login session, on any device, immediately.
 *
 * Cost: one Redis EXISTS per authenticated request (microseconds). Worth it
 * for proper "Sign out everywhere" semantics that JWT-stateless auth can't
 * provide on its own.
 *
 * Per-user tracking lets us power "Sign out all other sessions" without
 * scanning Redis: each session_id is added to a per-user set on first use.
 */
class TokenRevocationService
{
    private const REVOKED_PREFIX = 'auth:revoked-session:';

    private const USER_SESSIONS_PREFIX = 'auth:user-sessions:';

    private const SESSION_META_PREFIX = 'auth:session-meta:';

    private const SESSION_TOUCH_PREFIX = 'auth:session-touch:';

    // Fallback defaults for config('partna.sessions.*') — kept in sync with
    // config/partna.php. MAX_LIFETIME_SECONDS is ALSO used as revoke()'s default
    // parameter value below: PHP default-parameter expressions must be
    // compile-time constants, so that one reference can't become a config()
    // call — every other reference reads config() with this as the fallback.
    //
    // Rolling last-activity resolution. One Redis SET NX per request in the
    // common case; the meta hash is only written when the interval elapses.
    private const TOUCH_INTERVAL_SECONDS = 600;

    // Refresh tokens last ~30 days in Supabase; outlast access tokens but not
    // refresh tokens, so a revoked session is invalidated for its full life.
    private const MAX_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

    /**
     * Revoke a session. ttlSeconds should be the remaining lifetime of the
     * underlying refresh token so the Redis entry self-cleans exactly when
     * the token would have expired anyway. Defaults to the max refresh-token
     * lifetime when the caller can't compute it precisely.
     */
    public function revoke(string $sessionId, int $ttlSeconds = self::MAX_LIFETIME_SECONDS): void
    {
        if ($sessionId === '' || $ttlSeconds <= 0) {
            return;
        }

        Redis::setex(self::REVOKED_PREFIX.$sessionId, $ttlSeconds, '1');
    }

    /** True if this session_id has been revoked (logout, admin action, etc.). */
    public function isRevoked(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        return (bool) Redis::exists(self::REVOKED_PREFIX.$sessionId);
    }

    /**
     * Track that a session_id was seen for this user. Called from the JWT
     * middleware on every authenticated request (Redis SADD is idempotent
     * and cheap, so re-adding the same id is fine).
     */
    public function trackForUser(string $userId, string $sessionId, ?array $metadata = null): void
    {
        if ($userId === '' || $sessionId === '') {
            return;
        }

        $setKey = self::USER_SESSIONS_PREFIX.$userId;
        Redis::sadd($setKey, $sessionId);
        // Set the set's TTL only when it has none. Bumping it on every request
        // (the old behaviour) pinned the whole set — and every dead member in
        // it — for the full 30 days as long as ANY session stayed active, so
        // the tracked set never expired. ttl() < 0 means -1 (key exists, no
        // expiry) or -2 (key missing): both are "no live TTL". A live session
        // re-adds itself on its next request, and reconciliation drops dead
        // members, so a set that resets its TTL loses nothing that matters.
        // (EXPIRE ... NX is the atomic equivalent but the flag isn't uniformly
        // supported across the phpredis/predis clients this app runs on.)
        if (Redis::ttl($setKey) < 0) {
            Redis::expire($setKey, $this->maxLifetimeSeconds());
        }

        if ($metadata !== null) {
            // Hash of session metadata (first-seen device/location, etc.) used
            // by the Active Sessions UI. Stored only on first sight — never
            // overwritten — to preserve "first signed in from" semantics.
            //
            // LIFE-2 + DINT-2: the claim-init-populate-expire sequence (HSETNX
            // sentinel → HMSET fields → EXPIRE ttl) runs as a single Lua script
            // so it's atomic server-side. Without this, a crash between the
            // HSETNX and the EXPIRE (process death, connection drop) could
            // leave the hash permanently TTL-less — pinned in Redis forever
            // with only the `_init` sentinel set. The script itself encodes
            // "populate only if we won the init" so no other caller can
            // partial-overwrite. SEC-3: store transformed values instead of
            // raw IP/UA to limit PII exposure in Redis logs/monitoring tools;
            // truncation is intentional (see truncateIp()).
            $metaKey = self::SESSION_META_PREFIX.$sessionId;

            // Redis::eval() runs a server-side Lua script (Redis's built-in EVAL
            // command) — unrelated to PHP eval(). The script below is a fixed
            // literal (heredoc, not built from any request/user input); values
            // are passed as Redis ARGV (data), never interpolated into the
            // script text, so there is no injection surface.
            Redis::eval(
                <<<'LUA'
                if redis.call('HSETNX', KEYS[1], '_init', '1') == 1 then
                    redis.call('HMSET', KEYS[1], 'user_id', ARGV[1], 'created_at', ARGV[2], 'ip_prefix', ARGV[3], 'browser_family', ARGV[4], 'platform', ARGV[5])
                    redis.call('EXPIRE', KEYS[1], ARGV[6])
                    return 1
                end
                return 0
                LUA,
                1,
                $metaKey,
                $userId,
                (string) time(),
                $this->truncateIp((string) ($metadata['ip'] ?? '')),
                $this->parseUaBrowserFamily((string) ($metadata['user_agent'] ?? '')),
                $this->parseUaPlatform((string) ($metadata['user_agent'] ?? '')),
                (string) $this->maxLifetimeSeconds(),
            );
        }

        // Rolling "last active" marker for the Active Sessions UI — separate
        // from the write-once first-seen metadata above.
        $this->touchLastSeen($sessionId);
    }

    /**
     * Record a rolling last-activity timestamp for a session, at most once per
     * TOUCH_INTERVAL_SECONDS. SET NX EX on a marker key is the throttle:
     * winning the race (marker absent) writes the timestamp; losing skips.
     */
    private function touchLastSeen(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $won = (bool) Redis::set(self::SESSION_TOUCH_PREFIX.$sessionId, '1', 'EX', $this->touchIntervalSeconds(), 'NX');
        if (! $won) {
            return;
        }

        $metaKey = self::SESSION_META_PREFIX.$sessionId;
        Redis::hset($metaKey, 'last_seen_at', (string) time());
        Redis::expire($metaKey, $this->maxLifetimeSeconds());
    }

    /** Config-driven rolling touch interval (config/partna.php: sessions.touch_interval_seconds). */
    private function touchIntervalSeconds(): int
    {
        return (int) config('partna.sessions.touch_interval_seconds', self::TOUCH_INTERVAL_SECONDS);
    }

    /** Config-driven Redis TTL for tracking/meta entries (config/partna.php: sessions.max_lifetime_seconds). */
    private function maxLifetimeSeconds(): int
    {
        return (int) config('partna.sessions.max_lifetime_seconds', self::MAX_LIFETIME_SECONDS);
    }

    /**
     * Revoke every session_id ever tracked for this user EXCEPT the one
     * specified (typically the current request's session, so the user stays
     * logged in on the device they clicked "Sign out everywhere" from).
     *
     * @return int Number of sessions revoked.
     */
    public function revokeAllForUser(string $userId, ?string $exceptSessionId = null): int
    {
        if ($userId === '') {
            return 0;
        }

        $setKey = self::USER_SESSIONS_PREFIX.$userId;
        $sessionIds = Redis::smembers($setKey) ?: [];
        $revoked = 0;

        foreach ($sessionIds as $sessionId) {
            $sid = (string) $sessionId;
            if ($sid === '' || $sid === $exceptSessionId) {
                continue;
            }
            $this->revoke($sid);
            Redis::srem($setKey, $sid);
            $revoked++;
        }

        return $revoked;
    }

    /**
     * Drop a specific session from the user's tracked-sessions set without
     * adding it to the blocklist — used when a session naturally expires and
     * we want to keep the UI list tidy. Different from revoke() which keeps
     * the entry in the blocklist for the rest of its TTL.
     */
    public function untrack(string $userId, string $sessionId): void
    {
        if ($userId === '' || $sessionId === '') {
            return;
        }

        Redis::srem(self::USER_SESSIONS_PREFIX.$userId, $sessionId);
        Redis::del(self::SESSION_META_PREFIX.$sessionId);
    }

    /**
     * List active (not-yet-revoked) sessions for a user, with metadata for
     * the Active Sessions UI. Filters out any sessions that have since been
     * revoked but haven't yet been pruned from the tracking set.
     *
     * Handles both new-format entries (ip_prefix / browser_family / platform)
     * and legacy entries written before SEC-3 (raw ip / user_agent fields).
     * Sessions persist up to 30 days so legacy rows may exist in production.
     *
     * Reconciles the tracked set against Supabase's live sessions before
     * reading, so the list self-heals against client-only logouts (which never
     * hit /api/sessions/logout, leaving dead session_ids tracked forever).
     * $currentSessionId, when given, is never dropped by that reconciliation —
     * the request is authenticated, so the user must never see "zero sessions".
     *
     * @return list<array{session_id:string,created_at:int,last_seen_at:?int,ip_prefix:string,browser_family:string,platform:string}>
     */
    public function listSessionsForUser(string $userId, ?string $currentSessionId = null): array
    {
        if ($userId === '') {
            return [];
        }

        $this->reconcileTrackedForUser($userId, $currentSessionId);

        $sessionIds = Redis::smembers(self::USER_SESSIONS_PREFIX.$userId) ?: [];
        $sessions = [];

        foreach ($sessionIds as $sessionId) {
            $sid = (string) $sessionId;
            if ($sid === '' || $this->isRevoked($sid)) {
                continue;
            }

            $meta = Redis::hgetall(self::SESSION_META_PREFIX.$sid) ?: [];

            // Legacy-compat: entries written before SEC-3 carry raw `ip` and
            // `user_agent`; parse them on read so callers always see the new shape.
            if (array_key_exists('ip_prefix', $meta)) {
                $ipPrefix = (string) $meta['ip_prefix'];
                $browserFamily = (string) $meta['browser_family'];
                $platform = (string) $meta['platform'];
            } else {
                $ipPrefix = $this->truncateIp((string) ($meta['ip'] ?? ''));
                $browserFamily = $this->parseUaBrowserFamily((string) ($meta['user_agent'] ?? ''));
                $platform = $this->parseUaPlatform((string) ($meta['user_agent'] ?? ''));
            }

            $sessions[] = [
                'session_id' => $sid,
                'created_at' => (int) ($meta['created_at'] ?? 0),
                // Rolling activity marker. Null on rows written before the
                // touch existed — the UI omits the "Active …" line for those.
                'last_seen_at' => isset($meta['last_seen_at']) ? (int) $meta['last_seen_at'] : null,
                'ip_prefix' => $ipPrefix,
                'browser_family' => $browserFamily,
                'platform' => $platform,
            ];
        }

        // Newest first
        usort($sessions, static fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $sessions;
    }

    /**
     * Drop tracked sessions Supabase no longer considers live. Self-heals the
     * per-user set against client-only logouts, which never call
     * /api/sessions/logout so dead session_ids are otherwise tracked forever.
     *
     * FAIL-OPEN: when the live-session lookup is unavailable (returns null),
     * nothing is pruned — a transient DB blip must never empty a security UI or
     * make the list under-report. Reconciliation only ever REMOVES sessions
     * that Supabase confirms are gone. $keepSessionId (the caller's current
     * session, if any) is never dropped.
     *
     * Returns null (rather than 0) when the lookup was unavailable, so the
     * scheduled sweep can tell "nothing to prune" apart from "couldn't reach
     * Supabase" and escalate a systemic outage. Read callers ignore the return.
     *
     * @return int|null Dead sessions untracked, or null if the lookup failed open.
     */
    public function reconcileTrackedForUser(string $userId, ?string $keepSessionId = null): ?int
    {
        if ($userId === '') {
            return 0;
        }

        $live = $this->liveSessionIds($userId);
        if ($live === null) {
            return null; // lookup unavailable — fail open, prune nothing
        }

        $dropped = 0;
        foreach (Redis::smembers(self::USER_SESSIONS_PREFIX.$userId) ?: [] as $sessionId) {
            $sid = (string) $sessionId;
            if ($sid === '' || $sid === $keepSessionId) {
                continue;
            }
            if (! in_array($sid, $live, true)) {
                $this->untrack($userId, $sid);
                $dropped++;
            }
        }

        return $dropped;
    }

    /**
     * Live session ids for a user from Supabase's source of truth, or null when
     * the lookup can't be trusted (any error). Callers treat null as fail-open.
     *
     * @return list<string>|null
     */
    private function liveSessionIds(string $userId): ?array
    {
        try {
            return $this->fetchLiveSessionIds($userId);
        } catch (\Throwable $e) {
            // Never let a reconciliation lookup failure break the caller.
            Log::warning('sessions.reconcile.live_lookup_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Raw lookup of Supabase's live session ids via the core.live_session_ids
     * SECURITY DEFINER function (app_backend has no direct `auth` access). This
     * is the single seam tests override to inject a canned live set.
     *
     * Returns null when unavailable so callers fail open. The function is
     * Postgres-only; on the SQLite test connection there is no auth.sessions,
     * so we short-circuit to null (silently — this is expected, not an error).
     *
     * @return list<string>|null
     */
    protected function fetchLiveSessionIds(string $userId): ?array
    {
        if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
            return null;
        }

        // SETOF scalar function — the output column is named after the function,
        // so alias it to `id` via the table-alias column list.
        $rows = DB::connection('pgsql')->select(
            'SELECT id FROM core.live_session_ids(?) AS t(id)',
            [$userId],
        );

        return array_map(static fn ($row) => (string) $row->id, $rows);
    }

    // ---------------------------------------------------------------------------
    // Private helpers — SEC-3 PII minimisation
    // ---------------------------------------------------------------------------

    /**
     * Truncate an IP to its network prefix (last IPv4 octet zeroed; IPv6 first
     * 3 hextets only). Preserves "same network neighbourhood" signal without
     * storing the precise device address. Truncation is intentional (SEC-3).
     *
     * Known limitation: IPv6 compressed forms (::1, fe80::1, 2001:db8::1) produce
     * non-standard prefix strings because we explode on ':' rather than canonicalise
     * via inet_pton/inet_ntop. Deterministic (same input → same output) so still
     * useful as a network-neighbourhood signal; the value is for UI display only,
     * never used for routing/comparison/policy.
     */
    private function truncateIp(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        // IPv6: keep the first 3 colon-delimited groups, append "::"
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 3)).'::';
        }

        // IPv4: zero the last octet
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';

            return implode('.', $parts);
        }

        return $ip;
    }

    /**
     * Derive the browser family from a User-Agent string.
     * Order matters — Edge/OPR must be checked before Chrome/Safari.
     */
    private function parseUaBrowserFamily(string $ua): string
    {
        if ($ua === '') {
            return 'Other';
        }

        return match (true) {
            (bool) preg_match('/Edg(?:e|\/)/i', $ua) => 'Edge',
            (bool) preg_match('/OPR\//i', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Browser',
            (bool) preg_match('/(?:Chrome|CriOS)\/[\d.]+/i', $ua) => 'Chrome',
            (bool) preg_match('/FxiOS\//i', $ua) => 'Firefox',
            (bool) preg_match('/Firefox\/[\d.]+/i', $ua) => 'Firefox',
            // Mobile Safari (iOS WebKit) before generic Safari
            (bool) preg_match('/Version\/[\d.]+ Mobile.*Safari/i', $ua) => 'iOS Safari',
            (bool) preg_match('/Safari\/[\d.]+/i', $ua) => 'Safari',
            default => 'Other',
        };
    }

    /**
     * Derive the OS/platform from a User-Agent string.
     */
    private function parseUaPlatform(string $ua): string
    {
        if ($ua === '') {
            return 'Other';
        }

        return match (true) {
            (bool) preg_match('/iPhone|iPad|iOS/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Windows/i', $ua) => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'macOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => 'Other',
        };
    }
}
