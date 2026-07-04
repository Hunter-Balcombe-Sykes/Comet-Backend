<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Route;

it('resolves every route platform default in the PlatformRegistry', function () {
    $registry = app(PlatformRegistry::class);

    $bad = [];
    foreach (Route::getRoutes() as $route) {
        $platform = $route->defaults['platform'] ?? null;
        if ($platform === null) {
            continue;
        }
        if (! $registry->has($platform)) {
            $bad[] = $route->methods()[0].' '.$route->uri()." → platform default '{$platform}'";
        }
    }

    expect($bad)->toBe([], "Route(s) with a 'platform' default that doesn't resolve in PlatformRegistry:\n  - ".implode("\n  - ", $bad));
});
