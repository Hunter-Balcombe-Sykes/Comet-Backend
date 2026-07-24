<?php

use App\Http\Controllers\Api\Platforms\AppleController;
use App\Http\Controllers\Api\Platforms\BookingController;
use App\Http\Controllers\Api\Platforms\CustomLinksController;
use App\Http\Controllers\Api\Platforms\DisplaySettingsController;
use App\Http\Controllers\Api\Platforms\EventbriteController;
use App\Http\Controllers\Api\Platforms\EventsController;
use App\Http\Controllers\Api\Platforms\FreshaController;
use App\Http\Controllers\Api\Platforms\GenericPlatformController;
use App\Http\Controllers\Api\Platforms\GoogleBusinessController;
use App\Http\Controllers\Api\Platforms\HumanitixController;
use App\Http\Controllers\Api\Platforms\InstagramController;
use App\Http\Controllers\Api\Platforms\IntegrationsMetaController;
use App\Http\Controllers\Api\Platforms\MenuContentController;
use App\Http\Controllers\Api\Platforms\MenuController;
use App\Http\Controllers\Api\Platforms\OnlineOrderingController;
use App\Http\Controllers\Api\Platforms\OpenTableController;
use App\Http\Controllers\Api\Platforms\RefreshController;
use App\Http\Controllers\Api\Platforms\ReservationsController;
use App\Http\Controllers\Api\Platforms\ShopController;
use App\Http\Controllers\Api\Platforms\SquareController;
use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use Illuminate\Support\Facades\Route;

// Per-user integration endpoints. Each controller is a per-platform adapter
// (kept under Api\Platforms — one external platform each); the feature/domain is
// "Integrations". Registered under /platforms (canonical — the dashboard calls
// these). The legacy /integrations alias was removed 2026-07-04 once the frontend
// finished migrating to /platforms.

$registerIntegrationRoutes = function (string $base): void {
    // Same stack as the main user API group (routes/api/user.php): pending-deletion
    // accounts are read-only here too, blocked at the HTTP edge with the cancel-prompt
    // body. Order matters — user.api resolves the professional BEFORE
    // EnforcePendingDeletionReadOnly inspects its status. The IntegrationConnectionPolicy
    // gate stays as defense-in-depth for non-HTTP and future by-UUID paths.
    $middleware = ['user.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'];

    // Cross-platform sync metadata — one call for the whole integrations index
    // ("Synced 2h ago" lines + sync-error badges) instead of touching every
    // per-platform endpoint.
    Route::prefix($base)
        ->middleware($middleware)
        ->group(function () {
            Route::get('/meta', IntegrationsMetaController::class);
        });

    Route::prefix("{$base}/fresha")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [FreshaController::class, 'connect'])->defaults('platform', 'fresha')->middleware('platform.available');
            // CA-W6: poll target for the 202 above. Deliberately WITHOUT
            // platform.available — a staff kill switch landing mid-poll must
            // not 503 an in-flight connect with no terminal state (mirrors
            // Skool/Apple/Eventbrite/Humanitix's identical connect/status routes).
            Route::get('/connect/status', [FreshaController::class, 'connectStatus']);
            Route::get('/team', [FreshaController::class, 'team']);
            Route::get('/url', [FreshaController::class, 'show']);
            Route::get('/employee-services', [FreshaController::class, 'employeeServices']);
            Route::post('/selection', [FreshaController::class, 'saveSelection']);
            Route::get('/selection', [FreshaController::class, 'selection']);
            Route::post('/service-visibility', [FreshaController::class, 'setServiceVisibility']);
            Route::delete('/', [FreshaController::class, 'forget']);
        });

    // Square Appointments — "Book now" deep link (just a stored URL, no scraping).
    // Fresha + Square are mutually exclusive booking providers (XOR), enforced in
    // the controllers (connect 409s when the other is already connected).
    Route::prefix("{$base}/square")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [SquareController::class, 'connect'])->defaults('platform', 'square')->middleware('platform.available');
            Route::get('/selection', [SquareController::class, 'selection']);
            Route::delete('/', [SquareController::class, 'forget']);
        });

    // Provider-agnostic shop endpoints. Registered under BOTH the canonical
    // /shop prefix and the legacy /shopify prefix (same controller — the
    // dashboard flips to /shop; the alias covers the deploy gap).
    foreach (['shop', 'shopify'] as $shopAlias) {
        Route::prefix("{$base}/{$shopAlias}")
            ->middleware($middleware)
            ->group(function () {
                Route::get('/brands', [ShopController::class, 'brands']);
                Route::post('/brands', [ShopController::class, 'addBrand']);
                Route::get('/brands/{id}/connect/status', [ShopController::class, 'connectStatus'])->where('id', '[A-Za-z0-9._-]+');
                Route::patch('/brands/{id}', [ShopController::class, 'updateBrand'])->where('id', '[A-Za-z0-9._-]+');
                Route::delete('/brands/{id}', [ShopController::class, 'removeBrand'])->where('id', '[A-Za-z0-9._-]+');
                Route::get('/brands/{id}/products', [ShopController::class, 'brandProducts'])->where('id', '[A-Za-z0-9._-]+');
                Route::post('/brands/{id}/catalog', [ShopController::class, 'catalog'])->where('id', '[A-Za-z0-9._-]+');
                Route::put('/brands/{id}/selection', [ShopController::class, 'setProducts'])->where('id', '[A-Za-z0-9._-]+');
                // Individual products (no parent store) — add by product-page URL.
                Route::post('/products', [ShopController::class, 'addProduct']);
                Route::delete('/products/{productId}', [ShopController::class, 'removeProduct'])->where('productId', '[A-Za-z0-9._-]+');
                Route::get('/selection', [ShopController::class, 'selection']);
                // GLOBAL shop link controls (2026-07-08) — one site-level choice
                // each (link mode + auto-latest), applied to every connected store.
                Route::get('/settings', [ShopController::class, 'settings']);
                Route::patch('/settings', [ShopController::class, 'updateSettings']);
                Route::delete('/', [ShopController::class, 'forget']);
            });
    }

    Route::prefix("{$base}/instagram")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [InstagramController::class, 'connect'])->middleware('platform.available:instagram');
            Route::get('/connect/status', [InstagramController::class, 'connectStatus']);
            Route::get('/selection', [InstagramController::class, 'selection']);
            // BE2: bio-link auto-sync popup contract — mirrors the Google Business
            // /synced + /synced/apply pair below.
            Route::get('/synced', [InstagramController::class, 'synced']);
            Route::post('/synced/apply', [InstagramController::class, 'applySync']);
            Route::delete('/', [InstagramController::class, 'forget']);
        });

    Route::prefix("{$base}/apple")
        ->middleware($middleware)
        ->group(function () {
            $musicPlatform = 'apple-music';
            $podcastPlatform = 'apple-podcast';
            Route::post('/music/connect', [AppleController::class, 'connectMusic'])->defaults('platform', $musicPlatform)->middleware('platform.available');
            // Deferred-connect poll endpoint (CA-W3) — always registered (mirrors
            // the registry loop's own supportsDeferredConnect() gate, which Apple's
            // bespoke group can't reach): a route that appears/disappears with an
            // env var is worse to debug than one that always 404s a nonexistent row.
            Route::get('/music/connect/status', [AppleController::class, 'musicConnectStatus']);
            Route::get('/music/recent', [AppleController::class, 'musicRecent']);
            Route::post('/music/highlights', [AppleController::class, 'musicHighlights']);
            // music reads → generic (platform=apple-music)
            Route::get('/music/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', $musicPlatform);
            Route::delete('/music/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', $musicPlatform);
            Route::get('/music/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $musicPlatform);
            Route::post('/podcast/connect', [AppleController::class, 'connectPodcast'])->defaults('platform', $podcastPlatform)->middleware('platform.available');
            Route::get('/podcast/connect/status', [AppleController::class, 'podcastConnectStatus']);
            Route::get('/podcast/recent', [AppleController::class, 'podcastRecent']);
            Route::post('/podcast/highlights', [AppleController::class, 'podcastHighlights']);
            // podcast reads → generic (platform=apple-podcast)
            Route::get('/podcast/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', $podcastPlatform);
            Route::delete('/podcast/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', $podcastPlatform);
            Route::get('/podcast/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $podcastPlatform);
            Route::delete('/music', [AppleController::class, 'forgetMusic']);
            Route::delete('/podcast', [AppleController::class, 'forgetPodcast']);
            Route::delete('/', [AppleController::class, 'forget']);
        });

    // Events platforms — organiser/host accounts + individually-added events.
    foreach (['eventbrite' => EventbriteController::class, 'humanitix' => HumanitixController::class] as $slug => $controller) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($controller, $slug) {
                Route::post('/connect', [$controller, 'connect'])->defaults('platform', $slug)->middleware('platform.available');
                // Deferred-connect poll endpoint (CA-W5) — mirrors Apple's/Skool's
                // own status route, always registered regardless of the flag.
                Route::get('/connect/status', [$controller, 'connectStatus']);
                Route::get('/accounts', [$controller, 'accounts']);
                Route::delete('/accounts/{id}', [$controller, 'removeAccount'])->where('id', '[A-Za-z0-9._-]+');
                Route::post('/events', [$controller, 'addEvent']);
                Route::delete('/events/{id}', [$controller, 'removeEvent'])->where('id', '[A-Za-z0-9._-]+');
                Route::get('/selection', [$controller, 'selection']);
                Route::delete('/', [$controller, 'forget']);
            });
    }

    // Custom links — arbitrary URLs attached as branded link cards.
    Route::prefix("{$base}/custom")
        ->middleware($middleware)
        ->group(function () {
            Route::get('/links', [CustomLinksController::class, 'links']);
            Route::post('/links', [CustomLinksController::class, 'addLink']);
            Route::get('/links/{id}/status', [CustomLinksController::class, 'linkStatus'])->where('id', '[A-Za-z0-9._-]+');
            Route::put('/links/order', [CustomLinksController::class, 'reorderLinks']);
            Route::delete('/links/{id}', [CustomLinksController::class, 'removeLink'])->where('id', '[A-Za-z0-9._-]+');
            Route::delete('/', [CustomLinksController::class, 'forget']);
        });

    // ── Integration CATEGORIES ───────────────────────────────────────────
    // Smart URL-detect cards. /detect resolves the provider for a pasted link
    // and tells the dashboard which existing flow to run (Fresha picker / Square
    // / OpenTable embed); an unrecognised link is stored as a branded custom
    // card. Known providers still store under their own platform keys (fresha /
    // square / opentable); these category rows hold only the custom fallback
    // (booking / reservations) or the ordering links (online-ordering).
    // Dashboard-only — excluded from the public sitepage endpoint.
    Route::prefix("{$base}/booking")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/detect', [BookingController::class, 'detect']);
            Route::get('/detect/status', [BookingController::class, 'detectStatus']);
            Route::get('/status', [BookingController::class, 'status']);
            Route::delete('/', [BookingController::class, 'forget']);
        });

    Route::prefix("{$base}/reservations")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/detect', [ReservationsController::class, 'detect']);
            Route::get('/detect/status', [ReservationsController::class, 'detectStatus']);
            Route::get('/status', [ReservationsController::class, 'status']);
            Route::get('/suggestion', [ReservationsController::class, 'suggestion']);
            Route::delete('/', [ReservationsController::class, 'forget']);
        });

    Route::prefix("{$base}/online-ordering")
        ->middleware($middleware)
        ->group(function () {
            Route::get('/entries', [OnlineOrderingController::class, 'entries']);
            Route::post('/entries', [OnlineOrderingController::class, 'addEntry']);
            Route::get('/entries/{id}/status', [OnlineOrderingController::class, 'entryStatus'])->where('id', '[A-Za-z0-9._-]+');
            Route::delete('/entries/{id}', [OnlineOrderingController::class, 'removeEntry'])->where('id', '[A-Za-z0-9._-]+');
            Route::delete('/', [OnlineOrderingController::class, 'forget']);
        });

    // Tickets & Events — smart-detect facade over the Eventbrite + Humanitix
    // platforms plus custom links. /add detects the platform and decides event vs
    // organiser-account vs custom; /selection returns the unified accounts +
    // events list (each row tagged with its per-platform removePath); custom
    // (events-custom) cards are removed here, platform rows via their own routes.
    Route::prefix("{$base}/events")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/add', [EventsController::class, 'add']);
            Route::get('/selection', [EventsController::class, 'selection']);
            Route::delete('/custom/{id}', [EventsController::class, 'removeCustom'])->where('id', '[A-Za-z0-9._-]+');
        });

    // Menu — the fetched Uber Eats / DoorDash menu (the single site.menus row),
    // auto-populated from the online-ordering links, plus direct write paths:
    // /scan/apply merges AI-extracted items from a user-uploaded menu photo/PDF
    // (independent of any scrape — see MenuScanApplier); /categories + /items
    // are owner-authored (manual) content management (MenuContentController).
    // No connect step for the scrape side; /refresh re-scrapes. Authenticated
    // dashboard surface — the menu is ALSO served publicly, via PublicMenuController.
    Route::prefix("{$base}/menu")
        ->middleware($middleware)
        ->group(function () {
            Route::get('/status', [MenuController::class, 'status']);
            Route::get('/', [MenuController::class, 'show']);
            Route::post('/refresh', [MenuController::class, 'refresh']);
            Route::post('/scan/apply', [MenuController::class, 'applyScan']);

            // Owner-authored (manual) menu content. {category}/{item} are UUIDs,
            // resolved strictly through the caller's own menu inside the controller.
            // whereUuid (not a loose alnum regex) so a malformed id 404s at the router
            // — menu_categories.id/menu_items.id are real Postgres `uuid` columns, and
            // a non-UUID literal reaching MenuCategory::find()/MenuItem::find() raises
            // 22P02 (invalid input syntax for type uuid), an uncaught 500 on live
            // Postgres that SQLite's TEXT-typed test schema can't reproduce. Matches
            // the whereUuid() convention already used throughout routes/api/staff.php
            // and routes/api/user.php for uuid-typed route params.
            Route::post('/categories', [MenuContentController::class, 'createCategory']);
            Route::patch('/categories/{category}', [MenuContentController::class, 'updateCategory'])->whereUuid('category');
            Route::delete('/categories/{category}', [MenuContentController::class, 'deleteCategory'])->whereUuid('category');
            Route::post('/items', [MenuContentController::class, 'createItem']);
            // Bulk delete before the {item} routes — 'bulk-delete' must never be
            // captured as an {item} id (whereUuid already prevents it; order is
            // belt-and-braces).
            Route::post('/items/bulk-delete', [MenuContentController::class, 'bulkDeleteItems']);
            Route::patch('/items/{item}', [MenuContentController::class, 'updateItem'])->whereUuid('item');
            Route::delete('/items/{item}', [MenuContentController::class, 'deleteItem'])->whereUuid('item');
        });

    // ── Registry-driven simple-archetype routes (FOUND-21) ───────────────────
    // One loop replaces the former $singleSelection, $migratedReads, and link-only
    // social loops. Each descriptor declares its routeShape (in
    // PlatformRegistryServiceProvider); this loop emits the matching
    // connect / selection / forget (/accounts) endpoints with byte-identical wiring.
    // Bespoke platforms keep their standalone groups above. Adding a simple platform
    // = one ->routes(...) descriptor line, no edit here.
    foreach (app(PlatformRegistry::class)->all() as $slug => $descriptor) {
        $shape = $descriptor->routeShape();
        if ($shape === PlatformRouteShape::Bespoke) {
            continue;
        }

        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($descriptor, $slug, $shape) {
                // Null connectController = fully registry-driven (link-only, and
                // every platform migrated onto a ConnectStrategy).
                $connectController = $descriptor->connectController() ?? GenericPlatformController::class;
                Route::post('/connect', [$connectController, 'connect'])->defaults('platform', $slug)->middleware('platform.available');

                if ($shape === PlatformRouteShape::SingleSelection) {
                    // selection + DELETE stay on the bespoke controller.
                    $controller = $descriptor->connectController();
                    Route::get('/selection', [$controller, 'selection']);
                    Route::delete('/', [$controller, 'forget']);

                    // Deferred-connect poll endpoint (CA-W4) — this branch
                    // RETURNS before the loop's own supportsDeferredConnect()
                    // gate below, so that gate can never emit this route for a
                    // SingleSelection descriptor. Wired explicitly instead,
                    // mirroring Apple's bespoke status routes (CA-W3): a route
                    // that appears/disappears with an env var is worse to debug
                    // than one that always 404s a nonexistent row. Deliberately
                    // NOT ->deferredConnect() on the descriptor — Skool has no
                    // ConnectStrategy to satisfy that flag's pinned invariant
                    // (RegistryConnectCoverageTest) — see DefersBespokeConnect's
                    // own note. Gated by slug (not "every SingleSelection
                    // descriptor") because google-business — this shape's only
                    // other member — has no connectStatus() method; wiring the
                    // route for it too would 500 on the first hit.
                    if ($slug === 'skool') {
                        Route::get('/connect/status', [$controller, 'connectStatus']);
                    }

                    return;
                }

                // LinkOnly + MultiAccount: reads served by the registry-driven
                // GenericPlatformController via the platform route default.
                Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $slug);
                Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', $slug);

                // Deferred-connect poll endpoint (Unit 11 W6). Emitted wherever the
                // platform SUPPORTS deferral (a capability), independent of whether
                // config('partna.connect.deferred') currently ACTIVATES it — a route
                // that appears/disappears with an env var is worse to debug than one
                // that always 404s a nonexistent row. Reads supportsDeferredConnect()
                // — a declared flag — and MUST NOT call connectStrategy(): this loop
                // runs at boot, and resolving the lazy factory here would bake a real
                // scraper into the descriptor before any test can mock it (the same
                // trap hasHighlights()/multiAccount() below are already careful to
                // avoid).
                if ($descriptor->supportsDeferredConnect()) {
                    Route::get('/connect/status', [GenericPlatformController::class, 'connectStatus'])->defaults('platform', $slug);
                }

                if ($descriptor->multiAccount()) {
                    Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', $slug);
                    Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                        ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', $slug);
                }

                // Picker platforms: recent + curated highlights, strategy-driven.
                if ($descriptor->hasHighlights()) {
                    Route::get('/recent', [GenericPlatformController::class, 'recent'])->defaults('platform', $slug);
                    Route::post('/highlights', [GenericPlatformController::class, 'highlights'])->defaults('platform', $slug);
                }
            });
    }

    // OpenTable one-click connect: the rid-bearing link harvested from the
    // user's Google Business reservation provider (OpenTable blocks us from
    // resolving slug links ourselves). connect/selection/forget come from the
    // $migratedReads loop above (GenericPlatformController).
    Route::get("{$base}/opentable/suggestion", [OpenTableController::class, 'suggestion'])
        ->middleware($middleware);

    // Google Business "Automatically Synced Integrations": the reservation /
    // ordering / social connections the last connect auto-created, for the
    // connect modal's step 2. connect/selection/forget come from the loop above.
    Route::get("{$base}/google-business/synced", [GoogleBusinessController::class, 'synced'])
        ->middleware($middleware);
    // "Change to" — swap an existing connection for the one Google found (a conflict).
    Route::post("{$base}/google-business/synced/apply", [GoogleBusinessController::class, 'applySync'])
        ->middleware($middleware);

    // Manual per-platform refresh (dashboard refresh button) — re-pull the
    // auto-content platforms on demand. {platform} is validated against
    // the registry's refreshable set inside the controller; a per-user cooldown
    // keeps it from hammering the upstream scrapers.
    Route::post("{$base}/{platform}/refresh", [RefreshController::class, 'refresh'])
        ->where('platform', '[a-z-]+')
        ->middleware($middleware);

    // Poll target for the 202 above (RV-8). Same middleware/ownership scoping
    // as the POST — a foreign row is never visible to look up, so an empty
    // result 404s rather than 403ing.
    Route::get("{$base}/{platform}/refresh/status", [RefreshController::class, 'refreshStatus'])
        ->where('platform', '[a-z-]+')
        ->middleware($middleware);

    // Per-integration public display toggles (e.g. Google Business "show
    // reviews"). Registry-driven: platforms without declared toggles 404.
    Route::get("{$base}/{platform}/display-settings", [DisplaySettingsController::class, 'show'])
        ->where('platform', '[a-z-]+')
        ->middleware($middleware);
    Route::patch("{$base}/{platform}/display-settings", [DisplaySettingsController::class, 'update'])
        ->where('platform', '[a-z-]+')
        ->middleware($middleware);
};

$registerIntegrationRoutes('platforms');
