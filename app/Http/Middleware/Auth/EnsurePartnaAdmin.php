<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Admin-only gate. Requires staff record with role=admin.
class EnsurePartnaAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $uid = $request->attributes->get('supabase_uid');

        if (! $uid) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Unauthenticated'], 401);
        }

        $staff = $request->attributes->get('partna_staff');

        if (! $staff || ! $staff->isAdmin()) {
            return response()->json(['error' => 'admin_required', 'message' => 'Admin access required'], 403);
        }

        $request->attributes->set('partna_staff', $staff);

        return $next($request);
    }
}
