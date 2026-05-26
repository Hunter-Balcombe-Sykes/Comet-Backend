<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Controller;
use App\Services\Auth\TokenRevocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Active session management.
 *
 *   GET  /api/sessions                — list active sessions for the user
 *   POST /api/sessions/logout         — revoke the current session
 *   POST /api/sessions/logout-others  — revoke every OTHER session for the user
 *   DELETE /api/sessions/{sessionId}  — revoke a specific session
 *
 * Sessions are keyed by Supabase's `session_id` claim (stable across token
 * refreshes within a single login). Backend has no concept of sessions until
 * one is seen — VerifySupabaseJwt tracks each session_id on first sight.
 */
class SessionController extends Controller
{
    public function __construct(private readonly TokenRevocationService $revocation) {}

    /** GET /api/sessions */
    public function index(Request $request): JsonResponse
    {
        $uid = (string) $request->attributes->get('supabase_uid');
        $currentSessionId = (string) ($request->attributes->get('supabase_session_id') ?? '');

        $sessions = $this->revocation->listSessionsForUser($uid);

        // Decorate with "is_current" so the UI can render the "This device"
        // badge without inferring it client-side.
        $sessions = array_map(static function (array $s) use ($currentSessionId) {
            $s['is_current'] = ($s['session_id'] === $currentSessionId);

            return $s;
        }, $sessions);

        return response()->json(['sessions' => $sessions]);
    }

    /** POST /api/sessions/logout */
    public function logout(Request $request): JsonResponse
    {
        $uid = (string) $request->attributes->get('supabase_uid');
        $sessionId = (string) ($request->attributes->get('supabase_session_id') ?? '');
        $exp = $request->attributes->get('supabase_exp');

        if ($sessionId === '') {
            // No session_id means there's nothing to revoke — JWT older than
            // session tracking, or auth-server-fallback path. Acknowledge as
            // 204 so the frontend's local cleanup still proceeds normally.
            return response()->json(null, 204);
        }

        // Use the exact remaining lifetime so the Redis entry self-cleans
        // when the token would have expired naturally. Fall back to the
        // service default if exp is missing.
        $ttlSeconds = is_int($exp) ? max(0, $exp - time()) : null;
        $ttlSeconds === null ? $this->revocation->revoke($sessionId) : $this->revocation->revoke($sessionId, $ttlSeconds);
        $this->revocation->untrack($uid, $sessionId);

        return response()->json(null, 204);
    }

    /** POST /api/sessions/logout-others — revoke every session EXCEPT this one. */
    public function logoutOthers(Request $request): JsonResponse
    {
        $uid = (string) $request->attributes->get('supabase_uid');
        $currentSessionId = (string) ($request->attributes->get('supabase_session_id') ?? '');

        $count = $this->revocation->revokeAllForUser($uid, $currentSessionId !== '' ? $currentSessionId : null);

        return response()->json(['revoked' => $count]);
    }

    /** DELETE /api/sessions/{sessionId} — revoke a specific OTHER session. */
    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $uid = (string) $request->attributes->get('supabase_uid');
        $currentSessionId = (string) ($request->attributes->get('supabase_session_id') ?? '');

        // Refuse to revoke the current session through this endpoint — that's
        // what /logout is for, and clearing it here without the client-side
        // cleanup would leave the user in a half-logged-out state.
        if ($sessionId === $currentSessionId) {
            return response()->json([
                'message' => 'Use /sessions/logout to end the current session.',
            ], 400);
        }

        // Only act on sessions the user actually owns. listSessionsForUser
        // returns just their tracked set, so we use it as the authorization.
        $owned = array_column($this->revocation->listSessionsForUser($uid), 'session_id');
        if (! in_array($sessionId, $owned, true)) {
            return response()->json(null, 404);
        }

        $this->revocation->revoke($sessionId);
        $this->revocation->untrack($uid, $sessionId);

        return response()->json(null, 204);
    }
}
