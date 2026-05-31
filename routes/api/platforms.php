<?php

use App\Http\Controllers\Api\Platforms\FreshaController;
use App\Http\Controllers\Api\Platforms\InstagramController;
use App\Http\Controllers\Api\Platforms\ShopifyController;
use App\Http\Controllers\Api\Platforms\StanController;
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
