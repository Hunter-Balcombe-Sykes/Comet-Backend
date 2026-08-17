<?php

use App\Catalog\LegacyPlatformMap;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use Illuminate\Support\Facades\Route;

// Defined and used in THIS file only — a cross-file Pest helper breaks the
// parallel runner in this repo.
function brandRouteExists(string $method, string $uri): bool
{
    foreach (Route::getRoutes() as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            return true;
        }
    }

    return false;
}

it('emits the platform route contract for every derived brand', function () {
    $derived = array_filter(
        app(PlatformRegistry::class)->all(),
        fn ($d) => $d->routeShape() === PlatformRouteShape::Brand
    );

    // Guard the guard: an empty set would make every assertion below vacuous.
    expect($derived)->not->toBeEmpty();

    foreach ($derived as $slug => $descriptor) {
        expect(brandRouteExists('POST', "api/platforms/{$slug}/connect"))->toBeTrue("missing connect for {$slug}");
        expect(brandRouteExists('GET', "api/platforms/{$slug}/selection"))->toBeTrue("missing selection for {$slug}");
        expect(brandRouteExists('DELETE', "api/platforms/{$slug}"))->toBeTrue("missing delete for {$slug}");
    }
});

it('keeps the family-wide disconnect endpoints', function () {
    // Owner ruling 4: the per-brand DELETE is an ADDITION. The family-wide
    // endpoint keeps meaning "remove every connection of this class" and is what
    // a connect sheet's clear-all calls.
    expect(brandRouteExists('DELETE', 'api/platforms/online-ordering'))->toBeTrue();
    expect(brandRouteExists('DELETE', 'api/platforms/booking'))->toBeTrue();
    expect(brandRouteExists('DELETE', 'api/platforms/reservations'))->toBeTrue();
});

it('routes derived connects to connectBrand, never to connect', function () {
    // connect() writes through writeConnection(), which keys the surface off
    // platform() — for a derived brand that is the BRAND key, and storing it as
    // a surface trips IntegrationConnection's saving guard. Pin the distinction.
    $derived = array_filter(
        app(PlatformRegistry::class)->all(),
        fn ($d) => $d->routeShape() === PlatformRouteShape::Brand
    );

    foreach (array_keys($derived) as $slug) {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === "api/platforms/{$slug}/connect" && in_array('POST', $r->methods(), true)
        );

        expect($route?->getActionMethod())->toBe('connectBrand', "{$slug} connect must use connectBrand");
    }
});

it('gives every derived descriptor the surface key its rows are written under', function () {
    $derived = array_filter(
        app(PlatformRegistry::class)->all(),
        fn ($d) => $d->routeShape() === PlatformRouteShape::Brand
    );

    foreach ($derived as $slug => $descriptor) {
        expect($descriptor->getSurfaceKey())->toBeString("{$slug} has no surface key");
        // The surface must reduce back to the slug, or the generated platform
        // column would never match the route it was written from.
        expect(LegacyPlatformMap::legacyFor($descriptor->getSurfaceKey()))->toBe($slug);
    }
});
