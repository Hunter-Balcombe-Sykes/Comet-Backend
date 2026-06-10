<?php

use App\Http\Controllers\Api\Platforms\AppleController;
use App\Http\Controllers\Api\Platforms\BandcampController;
use App\Http\Controllers\Api\Platforms\EventbriteController;
use App\Http\Controllers\Api\Platforms\FacebookController;
use App\Http\Controllers\Api\Platforms\FreshaController;
use App\Http\Controllers\Api\Platforms\HumanitixController;
use App\Http\Controllers\Api\Platforms\InstagramController;
use App\Http\Controllers\Api\Platforms\ShopController;
use App\Http\Controllers\Api\Platforms\SkoolController;
use App\Http\Controllers\Api\Platforms\SoundcloudController;
use App\Http\Controllers\Api\Platforms\SpotifyController;
use App\Http\Controllers\Api\Platforms\SquareController;
use App\Http\Controllers\Api\Platforms\TicketekController;
use App\Http\Controllers\Api\Platforms\TiktokController;
use App\Http\Controllers\Api\Platforms\TimelyController;
use App\Http\Controllers\Api\Platforms\YoutubeController;
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
                Route::put('/brands/{id}/selection', [ShopController::class, 'setProducts'])->where('id', '[A-Za-z0-9._-]+');
                Route::get('/selection', [ShopController::class, 'selection']);
                Route::delete('/', [ShopController::class, 'forget']);
            });
    }

    Route::prefix("{$base}/instagram")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [InstagramController::class, 'connect']);
            Route::get('/connect/status', [InstagramController::class, 'connectStatus']);
            Route::get('/posts', [InstagramController::class, 'posts']);
            Route::post('/selection', [InstagramController::class, 'saveSelection']);
            Route::get('/selection', [InstagramController::class, 'selection']);
            Route::delete('/', [InstagramController::class, 'forget']);
        });

    Route::prefix("{$base}/youtube")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [YoutubeController::class, 'connect']);
            Route::get('/recent', [YoutubeController::class, 'recent']);
            Route::post('/highlights', [YoutubeController::class, 'highlights']);
            Route::get('/selection', [YoutubeController::class, 'selection']);
            Route::delete('/', [YoutubeController::class, 'forget']);
        });

    Route::prefix("{$base}/apple")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/music/connect', [AppleController::class, 'connectMusic']);
            Route::get('/music/recent', [AppleController::class, 'musicRecent']);
            Route::post('/music/highlights', [AppleController::class, 'musicHighlights']);
            Route::get('/music/selection', [AppleController::class, 'musicSelection']);
            Route::post('/podcast/connect', [AppleController::class, 'connectPodcast']);
            Route::get('/podcast/recent', [AppleController::class, 'podcastRecent']);
            Route::post('/podcast/highlights', [AppleController::class, 'podcastHighlights']);
            Route::get('/podcast/selection', [AppleController::class, 'podcastSelection']);
            Route::delete('/music', [AppleController::class, 'forgetMusic']);
            Route::delete('/podcast', [AppleController::class, 'forgetPodcast']);
            Route::delete('/', [AppleController::class, 'forget']);
        });

    // Spotify + SoundCloud — oEmbed-resolved music embeds (connect/selection/forget).
    Route::prefix("{$base}/spotify")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [SpotifyController::class, 'connect']);
            Route::get('/selection', [SpotifyController::class, 'selection']);
            Route::delete('/', [SpotifyController::class, 'forget']);
        });

    Route::prefix("{$base}/soundcloud")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [SoundcloudController::class, 'connect']);
            Route::get('/selection', [SoundcloudController::class, 'selection']);
            Route::delete('/', [SoundcloudController::class, 'forget']);
        });

    // Bandcamp — Apple-style: connect + recent picker + curated highlights.
    Route::prefix("{$base}/bandcamp")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [BandcampController::class, 'connect']);
            Route::get('/recent', [BandcampController::class, 'recent']);
            Route::post('/highlights', [BandcampController::class, 'highlights']);
            Route::get('/selection', [BandcampController::class, 'selection']);
            Route::delete('/', [BandcampController::class, 'forget']);
        });

    Route::prefix("{$base}/tiktok")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [TiktokController::class, 'connect']);
            Route::get('/selection', [TiktokController::class, 'selection']);
            Route::delete('/', [TiktokController::class, 'forget']);
        });

    Route::prefix("{$base}/facebook")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [FacebookController::class, 'connect']);
            Route::get('/selection', [FacebookController::class, 'selection']);
            Route::delete('/', [FacebookController::class, 'forget']);
        });

    Route::prefix("{$base}/eventbrite")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [EventbriteController::class, 'connect']);
            Route::get('/selection', [EventbriteController::class, 'selection']);
            Route::delete('/', [EventbriteController::class, 'forget']);
        });

    // Humanitix — the Eventbrite twin (JSON-LD event scrape, no auth).
    Route::prefix("{$base}/humanitix")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [HumanitixController::class, 'connect']);
            Route::get('/selection', [HumanitixController::class, 'selection']);
            Route::delete('/', [HumanitixController::class, 'forget']);
        });

    // Skool — og-scraped community card.
    Route::prefix("{$base}/skool")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [SkoolController::class, 'connect']);
            Route::get('/selection', [SkoolController::class, 'selection']);
            Route::delete('/', [SkoolController::class, 'forget']);
        });

    // Plain external-link platforms: Ticketek tickets + Square / Timely booking.
    Route::prefix("{$base}/ticketek")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [TicketekController::class, 'connect']);
            Route::get('/selection', [TicketekController::class, 'selection']);
            Route::delete('/', [TicketekController::class, 'forget']);
        });

    Route::prefix("{$base}/square")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [SquareController::class, 'connect']);
            Route::get('/selection', [SquareController::class, 'selection']);
            Route::delete('/', [SquareController::class, 'forget']);
        });

    Route::prefix("{$base}/timely")
        ->middleware($middleware)
        ->group(function () {
            Route::post('/connect', [TimelyController::class, 'connect']);
            Route::get('/selection', [TimelyController::class, 'selection']);
            Route::delete('/', [TimelyController::class, 'forget']);
        });
};

$registerIntegrationRoutes('integrations');
$registerIntegrationRoutes('platforms');
