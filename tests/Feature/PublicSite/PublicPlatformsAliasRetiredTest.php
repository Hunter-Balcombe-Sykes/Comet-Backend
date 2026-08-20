<?php

// GET /api/public/profiles/{handle}/platforms was a legacy alias onto the same
// controller as /integrations, carried "until the sitepage flips". It was
// retired 2026-08-20 along with its CloudflarePurgeService purge URL.
//
// This guards the retirement the same way PublicMenuRouteRetiredTest guards the
// deleted menu route: the risk is not that someone re-adds the alias
// deliberately, it is that a route-file merge resurrects it and the duplicate
// silently comes back un-purged — the alias was cached at the edge, so a
// resurrected route that CloudflarePurgeService no longer knows about would
// serve stale integration cards with nothing to invalidate it.

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('no longer registers the /platforms alias', function () {
    $aliases = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn (string $uri) => str_ends_with($uri, '/platforms'))
        ->values()
        ->all();

    expect($aliases)->toBe([], 'the retired /platforms alias is registered again');
});

it('404s the retired alias for a handle that genuinely exists', function () {
    $user = User::factory()->create(['handle' => 'aliasgone']);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true]);

    // The handle resolves on /integrations, so a 404 here is the route being
    // gone — not the user being missing.
    $this->getJson('/api/public/profiles/aliasgone/integrations')->assertOk();
    $this->getJson('/api/public/profiles/aliasgone/platforms')->assertNotFound();
});

it('does not purge the retired alias URL', function () {
    // CloudflarePurgeServiceTest pins the exact URL list purgeHandle() builds.
    // This is the cheaper companion: it catches a re-add straight in the source
    // even if that test's expectations are ever relaxed.
    $source = (string) file_get_contents(base_path('app/Services/Cloudflare/CloudflarePurgeService.php'));

    expect($source)->not->toContain('/platforms"');
});
