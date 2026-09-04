<?php

// The superseded public-site payload lane was removed 2026-09-04 after a
// four-repo consumer search found no caller: the live sitepage app reads
// /public/profiles/{handle}, the mobile app reads no public surface, and the
// only reference to /public/site-by-slug anywhere was a dashboard proxy route
// with zero callers of its own. These assertions stop it growing back.

use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use App\Models\Views\PublicSitePayload;
use App\Services\Streaming\LiveStatusInjector;
use Illuminate\Support\Facades\Route;

it('no longer registers the legacy public-site payload routes', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($uris)->not->toContain('api/public/site');
    expect($uris)->not->toContain('api/public/site-by-slug');
});

it('404s the retired by-slug endpoint', function () {
    $this->getJson('/api/public/site-by-slug', ['X-Site-Subdomain' => 'anything'])
        ->assertNotFound();
});

it('has no PublicSiteController class left to route to', function () {
    expect(class_exists(PublicSiteController::class))
        ->toBeFalse();
});

it('leaves no class behind that reads the dropped payload view', function () {
    expect(class_exists(PublicSitePayload::class))->toBeFalse();
    expect(class_exists(LiveStatusInjector::class))->toBeFalse();
});
