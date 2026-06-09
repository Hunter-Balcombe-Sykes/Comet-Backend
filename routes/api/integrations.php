<?php

use App\Http\Controllers\Api\Platforms\AppleController;
use App\Http\Controllers\Api\Platforms\EventbriteController;
use App\Http\Controllers\Api\Platforms\FacebookController;
use App\Http\Controllers\Api\Platforms\FreshaController;
use App\Http\Controllers\Api\Platforms\InstagramController;
use App\Http\Controllers\Api\Platforms\ShopifyController;
use App\Http\Controllers\Api\Platforms\TiktokController;
use App\Http\Controllers\Api\Platforms\YoutubeController;
use Illuminate\Support\Facades\Route;

// Per-user integration endpoints. Each controller is a per-platform adapter
// (kept under Api\Platforms — one external platform each); the feature/domain is
// "Integrations". Registered under /integrations (canonical) AND /platforms (a
// legacy alias for the pre-rename dashboard, dropped once the frontend flips to
// /integrations). Promotion plan documented in FreshaController.

$registerIntegrationRoutes = function (string $base): void {
    Route::prefix("{$base}/fresha")
        ->middleware(['user.api', 'throttle:authenticated'])
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

    Route::prefix("{$base}/shopify")
        ->middleware(['user.api', 'throttle:authenticated'])
        ->group(function () {
            Route::get('/brands', [ShopifyController::class, 'brands']);
            Route::post('/brands', [ShopifyController::class, 'addBrand']);
            Route::patch('/brands/{id}', [ShopifyController::class, 'updateBrand'])->where('id', '[A-Za-z0-9._-]+');
            Route::delete('/brands/{id}', [ShopifyController::class, 'removeBrand'])->where('id', '[A-Za-z0-9._-]+');
            Route::get('/brands/{id}/products', [ShopifyController::class, 'brandProducts'])->where('id', '[A-Za-z0-9._-]+');
            Route::put('/brands/{id}/selection', [ShopifyController::class, 'setProducts'])->where('id', '[A-Za-z0-9._-]+');
            Route::get('/selection', [ShopifyController::class, 'selection']);
            Route::delete('/', [ShopifyController::class, 'forget']);
        });

    Route::prefix("{$base}/instagram")
        ->middleware(['user.api', 'throttle:authenticated'])
        ->group(function () {
            Route::post('/connect', [InstagramController::class, 'connect']);
            Route::get('/connect/status', [InstagramController::class, 'connectStatus']);
            Route::get('/posts', [InstagramController::class, 'posts']);
            Route::post('/selection', [InstagramController::class, 'saveSelection']);
            Route::get('/selection', [InstagramController::class, 'selection']);
            Route::delete('/', [InstagramController::class, 'forget']);
        });

    Route::prefix("{$base}/youtube")
        ->middleware(['user.api', 'throttle:authenticated'])
        ->group(function () {
            Route::post('/connect', [YoutubeController::class, 'connect']);
            Route::get('/recent', [YoutubeController::class, 'recent']);
            Route::post('/highlights', [YoutubeController::class, 'highlights']);
            Route::get('/selection', [YoutubeController::class, 'selection']);
            Route::delete('/', [YoutubeController::class, 'forget']);
        });

    Route::prefix("{$base}/apple")
        ->middleware(['user.api', 'throttle:authenticated'])
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

    Route::prefix("{$base}/tiktok")
        ->middleware(['user.api', 'throttle:authenticated'])
        ->group(function () {
            Route::post('/connect', [TiktokController::class, 'connect']);
            Route::get('/selection', [TiktokController::class, 'selection']);
            Route::delete('/', [TiktokController::class, 'forget']);
        });

    Route::prefix("{$base}/facebook")
        ->middleware(['user.api', 'throttle:authenticated'])
        ->group(function () {
            Route::post('/connect', [FacebookController::class, 'connect']);
            Route::get('/selection', [FacebookController::class, 'selection']);
            Route::delete('/', [FacebookController::class, 'forget']);
        });

    Route::prefix("{$base}/eventbrite")
        ->middleware(['user.api', 'throttle:authenticated'])
        ->group(function () {
            Route::post('/connect', [EventbriteController::class, 'connect']);
            Route::get('/selection', [EventbriteController::class, 'selection']);
            Route::delete('/', [EventbriteController::class, 'forget']);
        });
};

$registerIntegrationRoutes('integrations');
$registerIntegrationRoutes('platforms');
