<?php

namespace App\Http\Middleware\Auth;

use App\Models\Core\Staff\PartnaStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Staff-only gate. Checks supabase_uid maps to a PartnaStaff record.
// Accepts optional role parameters: middleware('staff:admin') restricts to a specific role,
// middleware('staff') allows any authenticated staff.
class EnsurePartnaStaff
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Set by VerifySupabaseJwt middleware
        $uid = $request->attributes->get('supabase_uid');

        if (! $uid) {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Unauthenticated'], 401);
        }

        $staff = $request->attributes->get('partna_staff')
            ?? PartnaStaff::query()->where('auth_user_id', $uid)->first();

        if (! $staff) {
            return response()->json(['error' => 'staff_required', 'message' => 'Staff access required'], 403);
        }

        if (! empty($roles) && ! in_array($staff->role, $roles, true)) {
            return response()->json(['error' => 'insufficient_staff_role', 'message' => 'Insufficient staff role'], 403);
        }

        // Attach for controllers to use
        $request->attributes->set('partna_staff', $staff);

        return $next($request);
    }
}
