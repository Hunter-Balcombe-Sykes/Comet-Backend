<?php

namespace App\Http\Middleware\Context;

use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OV-A: 503s a platform connect BEFORE the controller runs (hence before any
 * scrape) when staff have disabled that integration for the acting user — global
 * or segment rule. Platform comes from the middleware param or the route's
 * ->defaults('platform'). Complements the ManagesIntegrationConnection persistence
 * net, which blocks the DB write for every platform/verb.
 *
 * Apply as: ->middleware('platform.available') on routes that set a platform
 * default, or ->middleware('platform.available:<platform>') otherwise.
 */
class EnsurePlatformAvailable
{
    public function handle(Request $request, Closure $next, ?string $platform = null): Response
    {
        $platform ??= $request->route()?->defaults['platform'] ?? null;
        $user = $request->attributes->get('professional');

        // Fail-open when either is missing: user.api guarantees the user on these
        // routes, and connect routes always carry a platform — so this never fires
        // spuriously, and the persistence net is the backstop regardless.
        if ($platform !== null && $user instanceof User
            && ! FeatureAvailability::for($user)->allows('integration.'.$platform)) {
            abort(503, 'This integration is currently unavailable.');
        }

        return $next($request);
    }
}
