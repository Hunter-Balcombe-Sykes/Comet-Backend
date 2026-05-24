<?php

namespace App\Services\Auth;

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
        Redis::expire($setKey, self::MAX_LIFETIME_SECONDS);

        if ($metadata !== null) {
            // Hash of session metadata (first-seen ip, user-agent, etc) used
            // by the Active Sessions UI. Stored only on first sight — never
            // overwritten — to preserve "first signed in from" semantics.
            $metaKey = self::SESSION_META_PREFIX.$sessionId;
            if (! Redis::exists($metaKey)) {
                Redis::hmset($metaKey, [
                    'user_id' => $userId,
                    'created_at' => (string) time(),
                    'ip' => (string) ($metadata['ip'] ?? ''),
                    'user_agent' => (string) ($metadata['user_agent'] ?? ''),
                ]);
                Redis::expire($metaKey, self::MAX_LIFETIME_SECONDS);
            }
        }
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
     * @return list<array{session_id:string,created_at:int,ip:string,user_agent:string}>
     */
    public function listSessionsForUser(string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        $sessionIds = Redis::smembers(self::USER_SESSIONS_PREFIX.$userId) ?: [];
        $sessions = [];

        foreach ($sessionIds as $sessionId) {
            $sid = (string) $sessionId;
            if ($sid === '' || $this->isRevoked($sid)) {
                continue;
            }

            $meta = Redis::hgetall(self::SESSION_META_PREFIX.$sid) ?: [];
            $sessions[] = [
                'session_id' => $sid,
                'created_at' => (int) ($meta['created_at'] ?? 0),
                'ip' => (string) ($meta['ip'] ?? ''),
                'user_agent' => (string) ($meta['user_agent'] ?? ''),
            ];
        }

        // Newest first
        usort($sessions, static fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $sessions;
    }
}
