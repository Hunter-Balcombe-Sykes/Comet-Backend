<?php

use App\Http\Controllers\Api\Platforms\AppleController;
use App\Http\Controllers\Api\Platforms\BandcampController;
use App\Http\Controllers\Api\Platforms\BookingController;
use App\Http\Controllers\Api\Platforms\CustomLinksController;
use App\Http\Controllers\Api\Platforms\DeezerController;
use App\Http\Controllers\Api\Platforms\EventbriteController;
use App\Http\Controllers\Api\Platforms\EventsController;
use App\Http\Controllers\Api\Platforms\FreshaController;
use App\Http\Controllers\Api\Platforms\GenericPlatformController;
use App\Http\Controllers\Api\Platforms\GoogleBusinessController;
use App\Http\Controllers\Api\Platforms\HumanitixController;
use App\Http\Controllers\Api\Platforms\InstagramController;
use App\Http\Controllers\Api\Platforms\MenuController;
use App\Http\Controllers\Api\Platforms\NowBookitController;
use App\Http\Controllers\Api\Platforms\OnlineOrderingController;
use App\Http\Controllers\Api\Platforms\OpenTableController;
use App\Http\Controllers\Api\Platforms\PinterestController;
use App\Http\Controllers\Api\Platforms\RefreshController;
use App\Http\Controllers\Api\Platforms\ResDiaryController;
use App\Http\Controllers\Api\Platforms\ReservationsController;
use App\Http\Controllers\Api\Platforms\ShopController;
use App\Http\Controllers\Api\Platforms\SkoolController;
use App\Http\Controllers\Api\Platforms\SoundcloudController;
use App\Http\Controllers\Api\Platforms\SpotifyController;
use App\Http\Controllers\Api\Platforms\SquareController;
use App\Http\Controllers\Api\Platforms\StravaController;
use App\Http\Controllers\Api\Platforms\TwitchController;
use App\Http\Controllers\Api\Platforms\VimeoController;
use App\Http\Controllers\Api\Platforms\YoutubeController;
use App\Http\Controllers\Api\Platforms\YoutubeMusicController;
use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use Illuminate\Support\Facades\Route;

// Per-user integration endpoints. Each controller is a per-platform adapter
// (kept under Api\Platforms — one external platform each); the feature/domain is
// "Integrations". Registered under /integrations (canonical) AND /platforms (a
// legacy alias for the pre-rename dashboard, dropped once the frontend flips to
// /integrations). Promotion plan documented in FreshaController.

$registerIntegrationRoutes = function (string $base): void {
    // Same stack as the main user API group (routes/api/user.php): pending-deletion
    // accounts are read-only here too, blocked at the HTTP edge with the cancel-prompt
    // body. Order matters — user.api resolves the professional BEFORE
    // EnforcePendingDeletionReadOnly inspects its status. The IntegrationConnectionPolicy
    // gate stays as defense-in-depth for non-HTTP and future by-UUID paths.
    $middleware = ['user.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'];

    Route::prefix("{$base}/fresha")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [FreshaController::class, 'connect']);
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
            Route::post('/connect', [SquareController::class, 'connect']);
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
                Route::patch('/brands/{id}', [ShopController::class, 'updateBrand'])->where('id', '[A-Za-z0-9._-]+');
                Route::delete('/brands/{id}', [ShopController::class, 'removeBrand'])->where('id', '[A-Za-z0-9._-]+');
                Route::get('/brands/{id}/products', [ShopController::class, 'brandProducts'])->where('id', '[A-Za-z0-9._-]+');
                Route::post('/brands/{id}/catalog', [ShopController::class, 'catalog'])->where('id', '[A-Za-z0-9._-]+');
                Route::put('/brands/{id}/selection', [ShopController::class, 'setProducts'])->where('id', '[A-Za-z0-9._-]+');
                // Individual products (no parent store) — add by product-page URL.
                Route::post('/products', [ShopController::class, 'addProduct']);
                Route::delete('/products/{productId}', [ShopController::class, 'removeProduct'])->where('productId', '[A-Za-z0-9._-]+');
                Route::get('/selection', [ShopController::class, 'selection']);
                Route::delete('/', [ShopController::class, 'forget']);
            });
    }

    Route::prefix("{$base}/instagram")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [InstagramController::class, 'connect']);
            Route::get('/connect/status', [InstagramController::class, 'connectStatus']);
            Route::get('/selection', [InstagramController::class, 'selection']);
            Route::delete('/', [InstagramController::class, 'forget']);
        });

    Route::prefix("{$base}/youtube")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [YoutubeController::class, 'connect']);
            Route::get('/recent', [YoutubeController::class, 'recent']);
            Route::post('/highlights', [YoutubeController::class, 'highlights']);
            Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', 'youtube');
            Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', 'youtube');
            Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', 'youtube');
            Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', 'youtube');
        });

    Route::prefix("{$base}/apple")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/music/connect', [AppleController::class, 'connectMusic']);
            Route::get('/music/recent', [AppleController::class, 'musicRecent']);
            Route::post('/music/highlights', [AppleController::class, 'musicHighlights']);
            Route::get('/music/accounts', [AppleController::class, 'musicAccounts']);
            Route::delete('/music/accounts/{id}', [AppleController::class, 'removeMusicAccount'])->where('id', '[A-Za-z0-9._-]+');
            Route::get('/music/selection', [AppleController::class, 'musicSelection']);
            Route::post('/podcast/connect', [AppleController::class, 'connectPodcast']);
            Route::get('/podcast/recent', [AppleController::class, 'podcastRecent']);
            Route::post('/podcast/highlights', [AppleController::class, 'podcastHighlights']);
            Route::get('/podcast/accounts', [AppleController::class, 'podcastAccounts']);
            Route::delete('/podcast/accounts/{id}', [AppleController::class, 'removePodcastAccount'])->where('id', '[A-Za-z0-9._-]+');
            Route::get('/podcast/selection', [AppleController::class, 'podcastSelection']);
            Route::delete('/music', [AppleController::class, 'forgetMusic']);
            Route::delete('/podcast', [AppleController::class, 'forgetPodcast']);
            Route::delete('/', [AppleController::class, 'forget']);
        });

    // Bandcamp — Apple-style: connect + recent picker + curated highlights.
    Route::prefix("{$base}/bandcamp")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [BandcampController::class, 'connect']);
            Route::get('/recent', [BandcampController::class, 'recent']);
            Route::post('/highlights', [BandcampController::class, 'highlights']);
            Route::get('/accounts', [BandcampController::class, 'accounts']);
            Route::delete('/accounts/{id}', [BandcampController::class, 'removeAccount'])->where('id', '[A-Za-z0-9._-]+');
            Route::get('/selection', [BandcampController::class, 'selection']);
            Route::delete('/', [BandcampController::class, 'forget']);
        });

    // Vimeo — YouTube-style: connect + recent-uploads picker + curated highlights.
    Route::prefix("{$base}/vimeo")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [VimeoController::class, 'connect']);
            Route::get('/recent', [VimeoController::class, 'recent']);
            Route::post('/highlights', [VimeoController::class, 'highlights']);
            Route::get('/accounts', [VimeoController::class, 'accounts']);
            Route::delete('/accounts/{id}', [VimeoController::class, 'removeAccount'])->where('id', '[A-Za-z0-9._-]+');
            Route::get('/selection', [VimeoController::class, 'selection']);
            Route::delete('/', [VimeoController::class, 'forget']);
        });

    // YouTube Music — Vimeo-style: connect + recent-releases picker + curated
    // highlights, fed by the artist channel's uploads RSS.
    Route::prefix("{$base}/youtube-music")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [YoutubeMusicController::class, 'connect']);
            Route::get('/recent', [YoutubeMusicController::class, 'recent']);
            Route::post('/highlights', [YoutubeMusicController::class, 'highlights']);
            Route::get('/accounts', [YoutubeMusicController::class, 'accounts']);
            Route::delete('/accounts/{id}', [YoutubeMusicController::class, 'removeAccount'])->where('id', '[A-Za-z0-9._-]+');
            Route::get('/selection', [YoutubeMusicController::class, 'selection']);
            Route::delete('/', [YoutubeMusicController::class, 'forget']);
        });

    // Events platforms — organiser/host accounts + individually-added events.
    foreach (['eventbrite' => EventbriteController::class, 'humanitix' => HumanitixController::class] as $slug => $controller) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($controller) {
                Route::post('/connect', [$controller, 'connect']);
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
            Route::get('/status', [BookingController::class, 'status']);
            Route::delete('/', [BookingController::class, 'forget']);
        });

    Route::prefix("{$base}/reservations")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/detect', [ReservationsController::class, 'detect']);
            Route::get('/status', [ReservationsController::class, 'status']);
            Route::get('/suggestion', [ReservationsController::class, 'suggestion']);
            Route::delete('/', [ReservationsController::class, 'forget']);
        });

    Route::prefix("{$base}/online-ordering")
        ->middleware($middleware)
        ->group(function () {
            Route::get('/entries', [OnlineOrderingController::class, 'entries']);
            Route::post('/entries', [OnlineOrderingController::class, 'addEntry']);
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

    // Menu — read-only view of the fetched Uber Eats / DoorDash menu (the single
    // site.menus row), auto-populated from the online-ordering links. No connect
    // step; /refresh re-scrapes. Dashboard-only.
    Route::prefix("{$base}/menu")
        ->middleware($middleware)
        ->group(function () {
            Route::get('/status', [MenuController::class, 'status']);
            Route::get('/', [MenuController::class, 'show']);
            Route::post('/refresh', [MenuController::class, 'refresh']);
        });

    // Everything else is the uniform connect / selection / forget shape —
    // one stored selection per user, no picker step. Probe-verified keyless
    // platforms only (see the integrations v3 migration header).
    $singleSelection = [
        'twitch' => TwitchController::class,
        'pinterest' => PinterestController::class,
        'skool' => SkoolController::class,
        'strava' => StravaController::class,
        'google-business' => GoogleBusinessController::class,
        // OpenTable reservations — connect by restaurant link, render the
        // keyless reservation widget (rid read from the URL; no scraping).
        'opentable' => OpenTableController::class,
        // ResDiary + NowBookit reservations — keyless booking widgets, same
        // connect-by-link flow as OpenTable (the embed is built from the URL).
        'resdiary' => ResDiaryController::class,
        'nowbookit' => NowBookitController::class,
    ];
    // Watch/listen platforms in the uniform shape that also take multiple
    // accounts (the controller's supportsMultipleAccounts flag is the
    // source of truth; this list only gates the extra routes).
    $multiAccount = ['twitch'];
    foreach ($singleSelection as $slug => $controller) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($controller, $slug, $multiAccount) {
                Route::post('/connect', [$controller, 'connect']);
                Route::get('/selection', [$controller, 'selection']);
                Route::delete('/', [$controller, 'forget']);
                if (in_array($slug, $multiAccount, true)) {
                    Route::get('/accounts', [$controller, 'accounts']);
                    Route::delete('/accounts/{id}', [$controller, 'removeAccount'])->where('id', '[A-Za-z0-9._-]+');
                }
            });
    }

    // Migrated embed read paths (Plan 3a). connect() stays on the thin controller
    // (it fetches on connect); selection/accounts/forget are served by the
    // registry-driven GenericPlatformController via the platform route default.
    // URIs are unchanged from the $singleSelection version, so the golden-master
    // net-completeness count (52) is unaffected.
    $migratedReads = [
        'spotify' => SpotifyController::class,
        'soundcloud' => SoundcloudController::class,
        'deezer' => DeezerController::class,
    ];
    foreach ($migratedReads as $slug => $connectController) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($connectController, $slug) {
                Route::post('/connect', [$connectController, 'connect']);
                Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $slug);
                Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', $slug);
                Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                    ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', $slug);
                Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', $slug);
            });
    }

    // Link-only socials migrated to the registry-driven GenericPlatformController.
    // Each route carries its platform slug as a route DEFAULT (not a URI segment),
    // so the controller resolves its descriptor via request()->route('platform')
    // while the URIs stay per-platform (api/platforms/x/connect …). That keeps the
    // route table — and the golden-master net-completeness count — byte-identical
    // to the per-controller version these replace. Slugs are appended as they migrate.
    foreach (['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook'] as $slug) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($slug) {
                Route::post('/connect', [GenericPlatformController::class, 'connect'])->defaults('platform', $slug);
                Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $slug);
                Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', $slug);
            });
    }

    // OpenTable one-click connect: the rid-bearing link harvested from the
    // user's Google Business reservation provider (OpenTable blocks us from
    // resolving slug links ourselves). connect/selection/forget come from the
    // single-selection loop above.
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
    // PlatformRefresher::REFRESHABLE inside the controller; a per-user cooldown
    // keeps it from hammering the upstream scrapers.
    Route::post("{$base}/{platform}/refresh", [RefreshController::class, 'refresh'])
        ->where('platform', '[a-z-]+')
        ->middleware($middleware);
};

$registerIntegrationRoutes('integrations');
$registerIntegrationRoutes('platforms');
