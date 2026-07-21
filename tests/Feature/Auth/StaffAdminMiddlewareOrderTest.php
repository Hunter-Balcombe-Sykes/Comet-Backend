<?php

use App\Http\Middleware\Auth\EnsurePartnaAdmin;
use App\Http\Middleware\Auth\EnsurePartnaStaff;
use Illuminate\Support\Facades\Route;

/**
 * SEC-1 regression guard. EnsurePartnaAdmin ('staff.admin') trusts the
 * 'partna_staff' request attribute populated by EnsurePartnaStaff ('staff')
 * and hard-fails (403) when the attribute is missing — it no longer falls
 * back to its own DB lookup (see EnsurePartnaAdmin::handle()). That's only
 * safe as long as every route applying 'staff.admin' also applies 'staff'
 * EARLIER in its middleware list. If a future route group applied
 * staff.admin without staff first, every request would 403 instead of
 * silently doing an extra query — this test turns that misconfiguration
 * into a CI failure instead of a production incident.
 */
it('every staff.admin route runs staff earlier in its middleware stack', function () {
    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_values($route->gatherMiddleware());

        $adminIndex = null;
        $staffIndex = null;

        foreach ($middleware as $index => $name) {
            if ($adminIndex === null && ($name === 'staff.admin' || $name === EnsurePartnaAdmin::class)) {
                $adminIndex = $index;
            }
            if ($staffIndex === null && ($name === 'staff' || $name === EnsurePartnaStaff::class)) {
                $staffIndex = $index;
            }
        }

        if ($adminIndex === null) {
            continue;
        }

        if ($staffIndex === null || $staffIndex > $adminIndex) {
            $offenders[] = $route->uri().' ['.implode(',', $route->methods()).']';
        }
    }

    expect($offenders)->toBe([], 'Routes applying staff.admin without staff first: '.implode(', ', $offenders));
});

it('sanity: at least one route actually exercises the staff.admin ordering invariant', function () {
    $matched = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('staff.admin', $route->gatherMiddleware(), true));

    expect($matched)->not->toBeEmpty('No staff.admin routes found — adjust the guard or check the route wiring changed');
});
