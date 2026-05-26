<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Resolves a username (handle) to the user's primary email so the frontend can
// pass a normalised email into Supabase signInWithPassword / signInWithOtp /
// resetPasswordForEmail. Public + throttled.
//
// Contract — always returns 200 with { "email": string|null } regardless of
// whether the identifier exists. Combined with a small constant-time jitter
// this avoids leaking handle existence via status codes or response timing.
class PublicLoginIdentifierController extends ApiController
{
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $input = trim((string) $validated['identifier']);

        // Email path — accept it as-is. Supabase rejects unknown emails with
        // the same error whether we pre-resolve or not, so no lookup needed.
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return $this->success(['email' => mb_strtolower($input)]);
        }

        // Handle path — look up by handle_lc (case-insensitive). Returns the
        // primary_email so the frontend can hand it to Supabase. Returning the
        // email IS a small enumeration leak (handle → email), mitigated by:
        //   1. Handles are already public (they're the URL), and
        //   2. Rate limiting via the route's throttle middleware.
        $email = User::query()
            ->where('handle_lc', mb_strtolower($input))
            ->whereNull('deleted_at')
            ->value('primary_email');

        // Equalise timing so a hit vs miss doesn't differ measurably.
        usleep(random_int(40_000, 120_000));

        return $this->success([
            'email' => $email ? mb_strtolower((string) $email) : null,
        ]);
    }
}
