<?php

use App\Http\Middleware\Auth\RequireAal2;
use Illuminate\Support\Facades\Route;

/**
 * Sweep test — every staff route must enforce AAL2. Modeled on the
 * existing PolicyCoverageTest pattern (see tests/Feature/Security/).
 *
 * If you add a new staff route and forget the middleware, CI fails here.
 * If a specific staff route legitimately must remain AAL1 (e.g. a
 * "request MFA enrollment" route used pre-enrollment), add it to the
 * AAL2_EXEMPT_PATHS list with a one-line justification comment.
 */
const AAL2_EXEMPT_PATHS = [
    // path => justification
    // 'api/staff/mfa/setup' => 'pre-enrollment endpoint; called from aal1 sessions',
];

it('every staff API route is gated by require.aal2', function () {
    $staffRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/staff/'));

    expect($staffRoutes)->not->toBeEmpty('No staff routes found — adjust the prefix filter');

    $offenders = [];
    foreach ($staffRoutes as $route) {
        $path = $route->uri();
        if (array_key_exists($path, AAL2_EXEMPT_PATHS)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $hasAal2 = collect($middleware)->contains(function ($m) {
            return $m === 'require.aal2'
                || $m === RequireAal2::class;
        });

        if (! $hasAal2) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Staff routes missing require.aal2: '.implode(', ', $offenders));
});
