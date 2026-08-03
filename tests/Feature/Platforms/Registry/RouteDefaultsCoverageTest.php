<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\Architecture\SweepGuard;

/**
 * @param  iterable<Illuminate\Routing\Route>  $routes
 * @return array{matched: list<string>, bad: list<string>}
 */
function routeDefaultsSweep(iterable $routes, PlatformRegistry $registry): array
{
    $matched = [];
    $bad = [];

    foreach ($routes as $route) {
        $platform = $route->defaults['platform'] ?? null;
        if ($platform === null) {
            continue;
        }

        $label = $route->methods()[0].' '.$route->uri()." → platform default '{$platform}'";
        $matched[] = $label;

        if (! $registry->has($platform)) {
            $bad[] = $label;
        }
    }

    return ['matched' => $matched, 'bad' => $bad];
}

it('resolves every route platform default in the PlatformRegistry', function () {
    $registry = app(PlatformRegistry::class);
    $sweep = routeDefaultsSweep(Route::getRoutes(), $registry);

    // COV-GUARD-5. 109 routes carry a platform default today, but only ~10 are
    // static — the rest are registered by PlatformRegistry-driven loops in
    // routes/api/platforms.php. An empty registry registers none of those AND
    // makes "every default resolves" trivially true.
    SweepGuard::assertDenominator($sweep['matched'], 50, 'routes with a platform default');

    expect($sweep['bad'])->toBe([], "Route(s) with a 'platform' default that doesn't resolve in PlatformRegistry:\n  - ".implode("\n  - ", $sweep['bad']));
});

// Positive control (COV-GUARD-5). The route is constructed, NOT registered, so
// it cannot leak into Route::getRoutes() and redden the real sweep above.
it('proves the guard can fail: an unregistered platform default IS flagged', function () {
    $probe = (new Illuminate\Routing\Route(['GET'], 'api/__guard-probe', fn () => null))
        ->defaults('platform', 'totally-unregistered');

    $sweep = routeDefaultsSweep([$probe], app(PlatformRegistry::class));

    expect($sweep['matched'])->toHaveCount(1)
        ->and($sweep['bad'])->toHaveCount(1);
});

// Positive control (COV-GUARD-5): the denominator half — zero matched routes
// must redden the floor, proving the guard itself can fail.
it('proves the denominator guard can fail: zero matched routes is rejected', function () {
    expect(fn () => SweepGuard::assertDenominator([], 50, 'probe'))
        ->toThrow(ExpectationFailedException::class);
});
