<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Inbox access alone is not enough for this."
 *
 * WHY THIS EXISTS AND WHY IT IS NOT `require.aal2`:
 * RequireAal2 rejects every session that is not aal2 — including the majority
 * of users, who have no factor enrolled and therefore CAN never reach aal2.
 * Putting it on /me/data-export would deny GDPR portability to almost everyone.
 * The real requirement is weaker and better targeted: whoever pulls the full
 * PII bundle must have proved something beyond "I can read this mailbox".
 *
 * That distinction became load-bearing when /auth/confirm shipped: a magic
 * link or an invite now establishes a complete aal1 session with no password.
 * The client challenges for a second factor before it navigates, but a client
 * check is not a control — someone holding the link can take the token and
 * call the API directly.
 *
 * Supabase records how a session was actually established in the `amr` claim
 * (array of ['method' => string, 'timestamp' => int]), which VerifySupabaseJwt
 * exposes. A session carrying only an email-link method is inbox proof and
 * nothing more.
 *
 * SHADOW BY DEFAULT. This is a new denial on a live route, and the real-world
 * distribution of `amr` values is not yet known — in particular whether a
 * signup that ran signUp({password}) and then verifyOtp reports `password`,
 * `otp`, or both. Enforcing that blind is how a security fix becomes an
 * outage. So it logs `auth.strong_auth.would_deny` and lets the request
 * through until `partna.auth.strong_auth_enforce` is turned on. Read the logs,
 * confirm no legitimate cohort appears, THEN enforce.
 */
class RequireStrongAuth
{
    /**
     * Methods that prove more than mailbox control. `totp`/`phone`/`webauthn`
     * mirror Aal2FreshnessGate's MFA allowlist; `password`, `oauth` and `sso`
     * are credentials in their own right.
     */
    private const STRONG = ['password', 'oauth', 'sso', 'totp', 'phone', 'webauthn'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<int, array<string, mixed>> $amr */
        $amr = $request->attributes->get('supabase_amr', []);

        $methods = [];
        foreach ($amr as $entry) {
            $method = $entry['method'] ?? null;
            if (is_string($method) && $method !== '') {
                $methods[] = $method;
            }
        }

        $hasStrong = count(array_intersect($methods, self::STRONG)) > 0;
        if ($hasStrong) {
            return $next($request);
        }

        // Fail OPEN on an empty amr rather than denying a session whose token
        // simply predates amr being populated. Still logged: a sustained run of
        // empties means the claim is not arriving and this control is asleep.
        $enforce = (bool) config('partna.auth.strong_auth_enforce', false);

        Log::warning('auth.strong_auth.would_deny', [
            'path' => $request->path(),
            'uid' => $request->attributes->get('supabase_uid'),
            'aal' => $request->attributes->get('supabase_aal'),
            'methods' => $methods,
            'amr_empty' => $methods === [],
            'enforced' => $enforce,
        ]);

        if (! $enforce || $methods === []) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Sign in with your password to continue.',
            'code' => 'strong_auth_required',
            'error' => 'strong_auth_required',
        ], 401);
    }
}
