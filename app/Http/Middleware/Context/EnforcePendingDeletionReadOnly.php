<?php

namespace App\Http\Middleware\Context;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

// V2: Blocks write requests (non-GET/HEAD/OPTIONS) for professionals with
// status = pending_deletion. Returns 423 Locked with the scheduled deletion
// date so the frontend can render a cancel prompt.
//
// IMPORTANT: the cancel route must be excluded via withoutMiddleware() or this
// creates a logic deadlock — pending_deletion accounts could never cancel.
class EnforcePendingDeletionReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $professional = $request->attributes->get('professional');

        if (! $professional || ! $professional->isPendingDeletion()) {
            return $next($request);
        }

        // Allow safe methods through — status and audit info is still readable.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $retentionDays = (int) config('partna.soft_delete_retention_days', 30);
        $confirmedAt = $professional->deletion_confirmed_at;

        $deletesAt = null;
        if ($confirmedAt instanceof \DateTimeInterface) {
            $deletesAt = Carbon::instance($confirmedAt)->addDays($retentionDays)->toIso8601String();
        } elseif (is_string($confirmedAt) && $confirmedAt !== '') {
            $deletesAt = Carbon::parse($confirmedAt)->addDays($retentionDays)->toIso8601String();
        }

        // deletes_at is omitted (not sent as null) when no deletion timestamp
        // is known yet — keeps the payload honest about what the server
        // actually knows rather than implying a computed-but-empty value.
        $body = [
            'error' => 'account_pending_deletion',
            'message' => 'Account is pending deletion.',
            'pending_deletion' => true,
        ];
        if ($deletesAt !== null) {
            $body['deletes_at'] = $deletesAt;
        }

        return response()->json($body, 423);
    }
}
