<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any request whose supabase session is not aal2 (i.e. has not
 * passed at least one MFA factor verification this session).
 *
 * Always layered AFTER VerifySupabaseJwt — depends on the `supabase_aal`
 * request attribute set there.
 *
 * Returns 401 with code='mfa_required' so frontend can trigger a step-up
 * challenge modal and retry the original request.
 */
class RequireAal2
{
    public function handle(Request $request, Closure $next): Response
    {
        $aal = $request->attributes->get('supabase_aal', 'aal1');

        if ($aal !== 'aal2') {
            return response()->json([
                'message' => 'MFA required',
                'code' => 'mfa_required',
                'error' => 'mfa_required',
            ], 401);
        }

        return $next($request);
    }
}
