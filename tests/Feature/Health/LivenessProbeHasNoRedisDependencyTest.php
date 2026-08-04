<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Liveness Probe Has No Redis Dependency
|--------------------------------------------------------------------------
| Drill 03 (2026-08-05) measured GET /api/health taking 9-10s against a hung
| (not dead) Redis: throttle:health-check is cache-backed and its limiter
| performs ~3 sequential Redis ops, and read_timeout bounds an operation, not
| a request. A load balancer treating a 10s liveness response as dead pulls
| the app out of rotation over a cache problem. /health and /ping must stay
| unthrottled so they have a genuinely zero-dependency path; /ready and
| /health/scheduler do real work and must stay throttled.
|
| Middleware is resolved through the router (gatherMiddleware()), never by
| reading routes/api.php as text, so this fails the moment someone
| reintroduces the throttle on the wrong route.
*/

function liveness_route_middleware(string $uri): array
{
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === $uri);

    expect($route)->not->toBeNull("Route '{$uri}' is not registered — check routes/api.php.");

    return $route->gatherMiddleware();
}

function has_throttle_middleware(array $middleware): bool
{
    return collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'throttle:'));
}

it('does not throttle GET /api/health, keeping liveness zero-dependency', function () {
    expect(has_throttle_middleware(liveness_route_middleware('api/health')))->toBeFalse();
});

it('does not throttle GET /api/ping, keeping liveness zero-dependency', function () {
    expect(has_throttle_middleware(liveness_route_middleware('api/ping')))->toBeFalse();
});

it('still throttles GET /api/ready, which does real work', function () {
    expect(has_throttle_middleware(liveness_route_middleware('api/ready')))->toBeTrue();
});

it('still throttles GET /api/health/scheduler, which does real work', function () {
    expect(has_throttle_middleware(liveness_route_middleware('api/health/scheduler')))->toBeTrue();
});
