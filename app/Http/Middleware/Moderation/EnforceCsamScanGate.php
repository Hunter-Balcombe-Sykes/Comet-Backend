<?php

namespace App\Http\Middleware\Moderation;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth gate applied to public media-serve endpoints.
 *
 * Sets a request attribute that signals downstream queries to filter
 * processing_state: only 'ready' media is returned, with a grandfather
 * exemption for media uploaded before CSAM scanning was introduced.
 *
 * The primary enforcement lives in SitepageDataResolverService::applyCsamScanGate()
 * which applies a WHERE clause to every public site_media query. This middleware
 * exists as an annotation/signal layer so the gate is visible in the route
 * definition and auditable at the middleware level.
 */
class EnforceCsamScanGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('csam_scan_gate', true);
        return $next($request);
    }
}
