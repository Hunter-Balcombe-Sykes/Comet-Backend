<?php

use App\Http\Controllers\Api\Platforms\AppleController;
use App\Http\Controllers\Api\Platforms\FreshaController;
use App\Http\Controllers\Api\Platforms\InstagramController;
use App\Http\Controllers\Api\Platforms\ShopifyController;
use App\Http\Controllers\Api\Platforms\StanController;
use App\Http\Controllers\Api\Platforms\TiktokController;
use App\Http\Controllers\Api\Platforms\YoutubeController;
use Illuminate\Support\Facades\Route;

// Test-mode platform integration endpoints. Single-tenant cache, no auth.
// Promotion plan documented in App\Http\Controllers\Api\Platforms\FreshaController.

Route::prefix('platforms/fresha')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/connect', [FreshaController::class, 'connect']);
        Route::get('/team', [FreshaController::class, 'team']);
        Route::get('/url', [FreshaController::class, 'show']);
        Route::get('/employee-services', [FreshaController::class, 'employeeServices']);
        Route::post('/selection', [FreshaController::class, 'saveSelection']);
        Route::get('/selection', [FreshaController::class, 'selection']);
        Route::delete('/', [FreshaController::class, 'forget']);
    });

Route::prefix('platforms/shopify')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/connect', [ShopifyController::class, 'connect']);
        Route::get('/products', [ShopifyController::class, 'products']);
        Route::get('/url', [ShopifyController::class, 'show']);
        Route::post('/selection', [ShopifyController::class, 'saveSelection']);
        Route::get('/selection', [ShopifyController::class, 'selection']);
        Route::delete('/', [ShopifyController::class, 'forget']);
    });

Route::prefix('platforms/instagram')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/connect', [InstagramController::class, 'connect']);
        Route::get('/selection', [InstagramController::class, 'selection']);
        Route::delete('/', [InstagramController::class, 'forget']);
    });

Route::prefix('platforms/stan')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/connect', [StanController::class, 'connect']);
        Route::get('/selection', [StanController::class, 'selection']);
        Route::delete('/', [StanController::class, 'forget']);
    });

Route::prefix('platforms/youtube')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/connect', [YoutubeController::class, 'connect']);
        Route::get('/selection', [YoutubeController::class, 'selection']);
        Route::delete('/', [YoutubeController::class, 'forget']);
    });

Route::prefix('platforms/apple')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/music/connect', [AppleController::class, 'connectMusic']);
        Route::get('/music/selection', [AppleController::class, 'musicSelection']);
        Route::post('/podcast/connect', [AppleController::class, 'connectPodcast']);
        Route::get('/podcast/selection', [AppleController::class, 'podcastSelection']);
        Route::delete('/', [AppleController::class, 'forget']);
    });

Route::prefix('platforms/tiktok')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/connect', [TiktokController::class, 'connect']);
        Route::get('/selection', [TiktokController::class, 'selection']);
        Route::delete('/', [TiktokController::class, 'forget']);
    });
